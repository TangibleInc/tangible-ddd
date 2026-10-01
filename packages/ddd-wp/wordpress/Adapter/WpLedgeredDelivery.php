<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\Delivery\DeliveryBudgetExhausted;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\SystemClock;

/**
 * Per-callback invoker wrapping of DDD-registered callbacks on wp (register
 * 3.5, 5.1; X8 as amended by #59; WPC-2; wave1-notes "id-less payloads").
 *
 * WordPress delivers a fact as ONE Action Scheduler action on the fact's
 * unchanged 0.6 hook, `do_action($hook, $wrapped)`; every callback on the
 * hook runs in priority order, raw `add_action` callbacks included. Each
 * callback registered THROUGH DDD (integration_action(),
 * integration_listener(), WpHookSubscriptionRegistry, so the ProcessRunner
 * ignition and resume subscribers too) is bound via bind(), which wraps it
 * into the per-subscriber ledger semantics of IntegrationDelivery:
 *
 * - skipped when the ledger says delivered (or exhausted) for
 *   (subscriber id, event_id);
 * - run; success → mark_delivered;
 * - a throw → mark_failed(attempt + 1), logged, NOT rethrown: the rest of
 *   do_action still runs (isolation); a `{prefix}_ddd_redeliver` action is
 *   scheduled at now + IntegrationDelivery::backoff_seconds(attempt), which
 *   re-runs only the DDD subscribers of that hook through the same gate
 *   (redeliver()); raw callbacks never run twice;
 * - at the budget (budget()): its on_exhausted compensation (when it has
 *   one) and then the terminal marker; a throwing compensation stays
 *   pending and is re-fired on a later delivery; a fact that no longer
 *   decodes is marked exhausted without it ('compensation skipped:
 *   undecodable', as core IntegrationDelivery::poisoned());
 * - a `failed` pair whose subscriber is not bound when the redelivery runs
 *   (removed, context-only, closure id changed: WP8-9) spends one attempt
 *   per redelivery and is exhausted at the budget without a compensation;
 *   `wp ddd ops --abandon=<pair>` ends one at once;
 * - a ledger read or write that throws is contained per subscriber: logged,
 *   covered by a whole-fact redelivery, and the later subscribers still
 *   run; only when that redelivery cannot be scheduled is it rethrown (the
 *   fact's Action Scheduler action fails, as in 0.6).
 *
 * The ledger is `{prefix}_ddd_delivery_ledger` of the fact's consumer (the
 * prefix that owns the hook), and the gate is active only once that
 * consumer is at schema v8. Before that, and for payloads WITHOUT
 * `__event_id` (a hook fired by hand, a hand-built payload), callbacks run
 * directly with the 0.6 semantics (a throw propagates and aborts do_action),
 * and an id-less payload is noted once per hook per request. Raw
 * `add_action` callbacks are outside the guarantee, as documented.
 *
 * `{prefix}_ddd_redeliver` has no callback under 0.6: pending redeliveries
 * are lost on rollback unless `wp ddd drain --before-rollback` ran first.
 *
 * Budgets (wave 5 coordinator decision; budget()): a listener (every DDD
 * subscriber that is not a process ignition or resume) gets ONE attempt,
 * the 0.6 behaviour: its first throw is recorded as exhausted with the
 * error in last_error, its compensation fires once, and nothing repeats
 * the side effect. A consumer opts its listeners into retries with the
 * option `{prefix}_ddd_delivery_attempts`, a listener with #[Retries(n)],
 * and the filter `tangible_ddd_delivery_attempts` has the last word.
 * Process ignition, process resume and workflow ignition subscribers keep
 * the core budget (BUDGET, 5): they are idempotent by design.
 */
final class WpLedgeredDelivery {

  /** The core budget, kept by the process ignition and resume subscribers. */
  public const BUDGET = IntegrationDelivery::DEFAULT_BUDGET;

  /** A listener's attempts unless its consumer or the listener opts in (0.6: no retry). */
  public const LISTENER_ATTEMPTS = 1;

  /** Per consumer: `{prefix}_ddd_delivery_attempts` (via IDDDConfig::option()). */
  public const ATTEMPTS_OPTION = 'ddd_delivery_attempts';

  /** apply_filters(ATTEMPTS_FILTER, int $attempts, string $subscriber_id, string $prefix): int */
  public const ATTEMPTS_FILTER = 'tangible_ddd_delivery_attempts';

  /** Subscriber ids of the process kernel: `[{prefix}/](ignition|resume|workflow-ignition):…`. */
  private const KERNEL_ID = '~^(?:[a-z0-9_]+/)?(?:ignition|resume|workflow-ignition):~';

  /** @var array<string, array<string, array{id: string, priority: int, seq: int, event: string, invoke: \Closure, onExhausted: ?\Closure, attempts: ?int}>> hook => id => entry */
  private static array $bound = [];

  /** @var array<string, int> subscriber id => attempts its #[Retries] declares */
  private static array $declared = [];

  private static int $seq = 0;

  /** @var array<string, true> hooks already noted for an id-less payload this request */
  private static array $idlessNoted = [];

  /** @var array<string, true> hook|event_id with a redelivery scheduled this request */
  private static array $redeliveryScheduled = [];

  /** @var array<string, IDDDConfig> prefix => the consumer config register_delivery_hooks() saw */
  private static array $configs = [];

  /** The consumer whose `{prefix}_ddd_redeliver` hook is registered (register_delivery_hooks()). */
  public static function register_consumer(IDDDConfig $config): void {
    self::$configs[$config->prefix()] = $config;
  }

  /**
   * The relay-tick step for handler retries (register 5.1, delivery
   * layer): for every fact with a `failed` subscriber whose
   * `{prefix}_ddd_redeliver` action is gone (Action Scheduler failed it:
   * fatal, timeout, a ledger write that threw; or it was never stored),
   * schedule it again at max(now, last failure + backoff(attempts)).
   * Never while a pending or running redelivery exists for the same args.
   *
   * @return int redeliveries scheduled
   */
  public static function restore_redeliveries(IDDDConfig $config, ?\DateTimeImmutable $now = null, int $limit = 100): int {
    if (!WpSchema::is_v8($config) || !function_exists('as_schedule_single_action')) {
      return 0;
    }
    $now ??= self::clock()->now();
    $n = 0;
    foreach (self::orphans($config, $limit) as $row) {
      $failedAt = (new \DateTimeImmutable($row['updated_at'], new \DateTimeZone('UTC')))->getTimestamp();
      $due = max($now->getTimestamp(), $failedAt + IntegrationDelivery::backoff_seconds(max(1, $row['attempts'])));
      $id = as_schedule_single_action($due, $config->hook('ddd_redeliver'), $row['redelivery'], $config->as_group('outbox'));
      if ((int) $id === 0) {
        Log::write(null, sprintf('[ddd delivery] could not re-schedule the redelivery of event %s on %s', $row['event_id'], $config->prefix()), 'error');
        continue;
      }
      $n++;
    }
    return $n;
  }

  /**
   * Facts with a `failed` subscriber and no pending or running
   * `{prefix}_ddd_redeliver` action: what a 0.6 winner would never retry
   * (WpRollbackDrain counts them as remaining).
   */
  public static function orphan_count(IDDDConfig $config, int $limit = 1000): int {
    return WpSchema::is_v8($config) ? count(self::orphans($config, $limit)) : 0;
  }

  /** @return list<array{event_id: string, attempts: int, updated_at: string, redelivery: array<string, mixed>}> */
  private static function orphans(IDDDConfig $config, int $limit): array {
    if (!function_exists('as_has_scheduled_action')) {
      return [];
    }
    $hook = $config->hook('ddd_redeliver');
    $group = $config->as_group('outbox');
    return array_values(array_filter(
      (new WpDeliveryLedger($config->prefix()))->restorable($limit),
      static fn (array $row) => !as_has_scheduled_action($hook, $row['redelivery'], $group)
    ));
  }

  /**
   * Register a DDD callback on $hook and return the add_action callback.
   *
   * @param \Closure(mixed ...$params): void $invoke the 0.6 callback body (receives do_action's params)
   * @param (\Closure(IIntegrationEvent, \Throwable): void)|null $onExhausted
   * @param int|null $attempts the attempts the listener declares (#[Retries]); null = the consumer's
   */
  public static function bind(string $hook, string $eventClass, string $subscriberId, int $priority, \Closure $invoke, ?\Closure $onExhausted = null, ?int $attempts = null): \Closure {
    self::$bound[$hook][$subscriberId] = [
      'id' => $subscriberId,
      'priority' => $priority,
      'seq' => ++self::$seq,
      'event' => $eventClass,
      'invoke' => $invoke,
      'onExhausted' => $onExhausted,
      'attempts' => $attempts,
    ];
    if ($attempts !== null) {
      self::$declared[$subscriberId] = $attempts;
    } else {
      unset(self::$declared[$subscriberId]);
    }

    return static function (mixed ...$params) use ($hook, $subscriberId): void {
      $entry = self::$bound[$hook][$subscriberId] ?? null;
      if ($entry === null) {
        return; // unbound since (reset): behave like a removed callback
      }
      $eventId = self::eventIdOf($params);
      $ledger = $eventId === null ? null : self::ledgerFor($hook, $entry['event']);

      if ($eventId === null || $ledger === null) {
        if ($eventId === null) {
          self::noteIdless($hook);
        } else {
          $params[0] = WpLargeEnvelope::resolve($params[0]);
        }
        ($entry['invoke'])(...$params);
        return;
      }
      self::gateSafely($ledger, $hook, $entry, $eventId, $params[0]);
    };
  }

  /**
   * Deterministic subscriber id for a callable: `{kind}:{name}` where name is
   * Class::method, a function name, or `Closure@path:line` (path relative to
   * ABSPATH when under it), plus `#n` for the n-th repeat on the same hook.
   */
  public static function subscriber_id(string $hook, string $kind, callable $callback, ?string $name = null): string {
    $name ??= self::callableName($callback);
    $base = "$kind:$name";
    $id = $base;
    for ($n = 2; isset(self::$bound[$hook][$id]); $n++) {
      $id = "$base#$n";
    }
    return $id;
  }

  /**
   * The `{prefix}_ddd_redeliver` callback: re-run every DDD subscriber of
   * $hook through the ledger gate, in priority order. Delivered ones skip.
   *
   * @param array<string, mixed> $payload the wrapped envelope
   */
  public static function redeliver(string $hook, string $event_class, array $payload): void {
    $eventId = self::eventIdOf([$payload]);
    if ($eventId === null) {
      return;
    }
    unset(self::$redeliveryScheduled["$hook|$eventId"]);
    $ledger = self::ledgerFor($hook, $event_class);
    if ($ledger === null) {
      return;
    }

    $entries = array_values(self::$bound[$hook] ?? []);
    usort($entries, static fn (array $a, array $b) => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);
    foreach ($entries as $entry) {
      self::gateSafely($ledger, $hook, $entry, $eventId, $payload);
    }

    if ($ledger instanceof WpDeliveryLedger) {
      self::spendUnbound($ledger, $hook, $event_class, $eventId, $payload);
    }
  }

  /**
   * A `failed` pair of this fact whose subscriber is not bound on $hook in
   * this request (the listener was removed, it is registered only in some
   * contexts, or its closure id changed on a deploy, WP8-9) cannot run, but
   * it still spends one attempt per redelivery, so the delivery budget
   * bounds it: at the budget it is exhausted without a compensation (there
   * is no callback to take one from). Without this, such a row would stay
   * `failed` with an unchanged updated_at and every relay tick and drain
   * round would re-schedule a redelivery that does nothing.
   *
   * @param array<string, mixed> $wrapped
   */
  private static function spendUnbound(WpDeliveryLedger $ledger, string $hook, string $eventClass, string $eventId, array $wrapped): void {
    $prefix = (string) self::prefix_of($hook, $eventClass);
    foreach ($ledger->failures($eventId) as $id => $attempts) {
      if (isset(self::$bound[$hook][$id])) {
        continue;
      }
      $attempt = $attempts + 1;
      $budget = self::budget($prefix, $id);
      $reason = sprintf('subscriber %s is not bound on %s in this request (removed, registered only in some contexts, or its closure id changed)', $id, $hook);
      try {
        if ($attempt >= $budget) {
          $ledger->mark_failed_with($id, $eventId, $reason, $attempt, null);
          $ledger->mark_exhausted_because($id, $eventId, "$reason; exhausted without a compensation");
          Log::write(null, sprintf('[ddd delivery] subscriber %s exhausted its budget (%d) on %s event %s while unbound; no compensation ran', $id, $budget, $hook, $eventId), 'error');
          continue;
        }
        $ledger->mark_failed_with($id, $eventId, $reason, $attempt, self::redeliveryArgs($hook, $eventClass, $wrapped));
      } catch (\Throwable $e) {
        Log::write(null, sprintf('[ddd delivery] could not count the unbound subscriber %s on event %s: %s', $id, $eventId, $e->getMessage()), 'error');
        continue;
      }
      Log::write(null, sprintf('[ddd delivery] %s (attempt %d/%d)', $reason, $attempt, $budget), 'warning');
      self::scheduleRedelivery($hook, $eventClass, $eventId, $wrapped, $attempt);
    }
  }

  /**
   * The delivery budget of $subscriberId on consumer $prefix: how many
   * attempts it gets before it is exhausted (and its compensation fires).
   *
   * - process ignition, process resume, workflow ignition: BUDGET (5),
   *   whatever the consumer sets;
   * - a listener: the attempts its #[Retries] declares when it is bound in
   *   this request, else the option `{prefix}_ddd_delivery_attempts` when
   *   it holds a positive int, else LISTENER_ATTEMPTS (1); then the filter
   *   `tangible_ddd_delivery_attempts` ($attempts, $subscriber_id,
   *   $prefix). Never below 1.
   */
  public static function budget(string $prefix, string $subscriberId): int {
    if (preg_match(self::KERNEL_ID, $subscriberId) === 1) {
      return self::BUDGET;
    }
    $attempts = self::$declared[$subscriberId] ?? self::consumerAttempts($prefix);
    if (function_exists('apply_filters')) {
      $attempts = apply_filters(self::ATTEMPTS_FILTER, $attempts, $subscriberId, $prefix);
    }
    return max(1, self::positive($attempts) ?? 1);
  }

  private static function consumerAttempts(string $prefix): int {
    if (!function_exists('get_option')) {
      return self::LISTENER_ATTEMPTS;
    }
    return self::positive(get_option(self::configFor($prefix)->option(self::ATTEMPTS_OPTION), null)) ?? self::LISTENER_ATTEMPTS;
  }

  /** A positive int from an option or filter value, else null. */
  private static function positive(mixed $value): ?int {
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
      return (int) $value >= 1 ? (int) $value : null;
    }
    return null;
  }

  /** The DDD subscriber ids bound on $hook, in registration order. @return list<string> */
  public static function subscribers(string $hook): array {
    return array_keys(self::$bound[$hook] ?? []);
  }

  /** Forget the bindings of $hook (it was reset with remove_all_actions). */
  public static function unbind(string $hook): void {
    unset(self::$bound[$hook]);
  }

  /** @internal test seam */
  public static function reset_for_tests(): void {
    self::$bound = [];
    self::$declared = [];
    self::$seq = 0;
    self::$idlessNoted = [];
    self::$redeliveryScheduled = [];
    self::$configs = [];
  }

  /**
   * gate() with ledger storage failures contained per subscriber: a ledger
   * read or write that throws (the subscriber's own throw never escapes
   * gate()) is logged and covered by a whole-fact redelivery, so the
   * subscribers after it on the hook still run. Only when that redelivery
   * cannot be scheduled either is the failure rethrown, which fails the
   * Action Scheduler action of the fact (the 0.6 behaviour: an operator
   * retries it).
   *
   * @param array{id: string, priority: int, seq: int, event: string, invoke: \Closure, onExhausted: ?\Closure, attempts: ?int} $entry
   * @param array<string, mixed> $wrapped
   */
  private static function gateSafely(IDeliveryLedger $ledger, string $hook, array $entry, string $eventId, array $wrapped): void {
    try {
      self::gate($ledger, $hook, $entry, $eventId, $wrapped);
    } catch (\Throwable $e) {
      Log::write(null, sprintf(
        '[ddd delivery] ledger failure for subscriber %s on %s event %s; the fact is redelivered: %s',
        $entry['id'], $hook, $eventId, $e->getMessage()
      ), 'error');
      if (!self::scheduleRedelivery($hook, $entry['event'], $eventId, $wrapped, 1)) {
        throw $e;
      }
    }
  }

  /**
   * @param array{id: string, priority: int, seq: int, event: string, invoke: \Closure, onExhausted: ?\Closure, attempts: ?int} $entry
   * @param array<string, mixed> $wrapped
   */
  private static function gate(IDeliveryLedger $ledger, string $hook, array $entry, string $eventId, array $wrapped): void {
    $id = $entry['id'];
    if ($ledger->delivered($id, $eventId) || $ledger->exhausted($id, $eventId)) {
      return;
    }

    $budget = self::budget((string) self::prefix_of($hook, $entry['event']), $id);
    $attempts = $ledger->attempts($id, $eventId);
    if ($attempts >= $budget) {
      // Budget reached, compensation never completed: re-fire it.
      $last = new DeliveryBudgetExhausted($id, $eventId, $attempts, $ledger->last_error($id, $eventId));
      if (!self::exhaust($ledger, $hook, $entry, $eventId, $wrapped, $last, $budget)) {
        self::scheduleRedelivery($hook, $entry['event'], $eventId, $wrapped, $attempts);
      }
      return;
    }

    try {
      // A by-reference envelope (D6) is resolved inside the gate, so a
      // payload that cannot be loaded fails this attempt like any error.
      ($entry['invoke'])(WpLargeEnvelope::resolve($wrapped));
    } catch (\Throwable $e) {
      $attempt = $attempts + 1;
      if ($ledger instanceof WpDeliveryLedger) {
        $ledger->mark_failed_with($id, $eventId, $e->getMessage(), $attempt, self::redeliveryArgs($hook, $entry['event'], $wrapped));
      } else {
        $ledger->mark_failed($id, $eventId, $e->getMessage(), $attempt);
      }
      Log::write(null, sprintf(
        '[ddd delivery] subscriber %s failed on %s event %s (attempt %d/%d): %s',
        $id, $hook, $eventId, $attempt, $budget, $e->getMessage()
      ));
      if ($attempt < $budget || !self::exhaust($ledger, $hook, $entry, $eventId, $wrapped, $e, $budget)) {
        self::scheduleRedelivery($hook, $entry['event'], $eventId, $wrapped, $attempt);
      }
      return;
    }

    $ledger->mark_delivered($id, $eventId);
  }

  /**
   * @param array{id: string, onExhausted: ?\Closure, event: string} $entry
   * @param array<string, mixed> $wrapped
   */
  private static function exhaust(IDeliveryLedger $ledger, string $hook, array $entry, string $eventId, array $wrapped, \Throwable $last, int $budget): bool {
    if ($entry['onExhausted'] !== null) {
      try {
        $class = $entry['event'];
        $event = $class::from_payload(\TangibleDDD\Application\Events\IntegrationEnvelope::unwrap(WpLargeEnvelope::resolve($wrapped))->payload);
      } catch (\Throwable $e) {
        // The fact no longer decodes: the compensation can never be built.
        // Terminal without it, as core IntegrationDelivery::poisoned() does.
        $reason = sprintf('compensation skipped: undecodable payload (%s: %s)', get_class($e), $e->getMessage());
        $ledger instanceof WpDeliveryLedger
          ? $ledger->mark_exhausted_because($entry['id'], $eventId, $reason)
          : $ledger->mark_exhausted($entry['id'], $eventId);
        Log::write(null, sprintf('[ddd delivery] subscriber %s exhausted its budget (%d) on %s event %s; %s', $entry['id'], $budget, $hook, $eventId, $reason), 'error');
        return true;
      }
      try {
        ($entry['onExhausted'])($event, $last);
      } catch (\Throwable $e) {
        Log::write(null, sprintf(
          '[ddd delivery] failure callback of %s for event %s threw; compensation stays pending and is retried: %s',
          $entry['id'], $eventId, $e->getMessage()
        ));
        return false;
      }
    }
    $ledger->mark_exhausted($entry['id'], $eventId);
    Log::write(null, sprintf('[ddd delivery] subscriber %s exhausted its budget (%d) on %s event %s', $entry['id'], $budget, $hook, $eventId), 'error');
    return true;
  }

  /**
   * @param array<string, mixed> $wrapped
   * @return bool whether a redelivery of the fact is now queued (this call, or earlier in the request)
   */
  private static function scheduleRedelivery(string $hook, string $eventClass, string $eventId, array $wrapped, int $attempt): bool {
    if (isset(self::$redeliveryScheduled["$hook|$eventId"])) {
      return true;
    }
    if (!function_exists('as_schedule_single_action')) {
      return false;
    }
    $prefix = self::prefix_of($hook, $eventClass);
    if ($prefix === null) {
      return false;
    }
    $config = self::configFor($prefix);
    self::$redeliveryScheduled["$hook|$eventId"] = true;
    try {
      $id = as_schedule_single_action(
        self::clock()->now()->getTimestamp() + IntegrationDelivery::backoff_seconds($attempt),
        $config->hook('ddd_redeliver'),
        self::redeliveryArgs($hook, $eventClass, $wrapped),
        $config->as_group('outbox')
      );
    } catch (\Throwable $e) {
      Log::write(null, sprintf('[ddd delivery] scheduling the redelivery of %s event %s threw: %s', $hook, $eventId, $e->getMessage()), 'error');
      $id = 0;
    }
    if ((int) $id === 0) {
      // A `failed` ledger row with its redelivery args is scheduled again
      // by the next relay tick (restore_redeliveries()).
      unset(self::$redeliveryScheduled["$hook|$eventId"]);
      Log::write(null, sprintf('[ddd delivery] Action Scheduler did not store the redelivery of %s event %s; the relay tick re-schedules it', $hook, $eventId), 'error');
      return false;
    }
    return true;
  }

  /**
   * @param array<string, mixed> $wrapped
   * @return array{hook: string, event_class: string, payload: array<string, mixed>}
   */
  private static function redeliveryArgs(string $hook, string $eventClass, array $wrapped): array {
    return ['hook' => $hook, 'event_class' => $eventClass, 'payload' => $wrapped];
  }

  /** The consumer config of $prefix: the one register_delivery_hooks() saw, the registry's, or the 0.6 naming. */
  private static function configFor(string $prefix): IDDDConfig {
    if (isset(self::$configs[$prefix])) {
      return self::$configs[$prefix];
    }
    try {
      return ConsumerRegistry::config_for($prefix);
    } catch (\Throwable) {
      return new DDDConfig($prefix, '', '');
    }
  }

  private static function clock(): IClock {
    $clock = HostDefaults::get(IClock::class);
    return $clock instanceof IClock ? $clock : new SystemClock();
  }

  private static function ledgerFor(string $hook, string $eventClass): ?IDeliveryLedger {
    $prefix = self::prefix_of($hook, $eventClass);
    return $prefix !== null && WpSchema::is_v8($prefix) ? new WpDeliveryLedger($prefix) : null;
  }

  /** The consumer prefix owning $hook: `{prefix}_integration_{name}`. */
  public static function prefix_of(string $hook, string $eventClass): ?string {
    if (!is_a($eventClass, IIntegrationEvent::class, true) || !method_exists($eventClass, 'name')) {
      return null;
    }
    $suffix = '_integration_' . $eventClass::name();
    if (!str_ends_with($hook, $suffix) || strlen($hook) === strlen($suffix)) {
      return null;
    }
    $prefix = substr($hook, 0, -strlen($suffix));
    return preg_match('/^[a-z0-9_]+$/', $prefix) ? $prefix : null;
  }

  /** @param list<mixed> $params */
  private static function eventIdOf(array $params): ?string {
    if (count($params) !== 1 || !is_array($params[0])) {
      return null;
    }
    $id = $params[0]['__event_id'] ?? null;
    return is_string($id) && $id !== '' ? $id : null;
  }

  private static function noteIdless(string $hook): void {
    if (isset(self::$idlessNoted[$hook])) {
      return;
    }
    self::$idlessNoted[$hook] = true;
    Log::write(null, sprintf(
      '[DDD Integration] %s fired without __event_id: delivered to DDD subscribers directly, without ledger or ignition dedup',
      $hook
    ), 'notice');
  }

  private static function callableName(callable $callback): string {
    if ($callback instanceof \Closure) {
      $r = new \ReflectionFunction($callback);
      $file = (string) $r->getFileName();
      if (defined('ABSPATH') && str_starts_with($file, (string) ABSPATH)) {
        $file = substr($file, strlen((string) ABSPATH));
      }
      $this_ = $r->getClosureThis();
      return ($this_ !== null ? get_class($this_) . '@' : 'Closure@') . $file . ':' . $r->getStartLine();
    }
    if (is_array($callback)) {
      return (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . (string) $callback[1];
    }
    if (is_object($callback)) {
      return get_class($callback) . '::__invoke';
    }
    return (string) $callback;
  }
}
