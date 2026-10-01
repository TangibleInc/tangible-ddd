<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\DeliveryBudgetExhausted;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Support\Log;

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
 * - run; success → markDelivered;
 * - a throw → markFailed(attempt + 1), logged, NOT rethrown: the rest of
 *   do_action still runs (isolation); a `{prefix}_ddd_redeliver` action is
 *   scheduled at now + IntegrationDelivery::backoffSeconds(attempt), which
 *   re-runs only the DDD subscribers of that hook through the same gate
 *   (redeliver()); raw callbacks never run twice;
 * - at the budget (5): its onExhausted compensation (when it has one) and
 *   then the terminal marker; a throwing compensation stays pending and is
 *   re-fired on a later delivery.
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
 */
final class WpLedgeredDelivery {

  public const BUDGET = IntegrationDelivery::DEFAULT_BUDGET;

  /** @var array<string, array<string, array{id: string, priority: int, seq: int, event: string, invoke: \Closure, onExhausted: ?\Closure}>> hook => id => entry */
  private static array $bound = [];

  private static int $seq = 0;

  /** @var array<string, true> hooks already noted for an id-less payload this request */
  private static array $idlessNoted = [];

  /** @var array<string, true> hook|event_id with a redelivery scheduled this request */
  private static array $redeliveryScheduled = [];

  /**
   * Register a DDD callback on $hook and return the add_action callback.
   *
   * @param \Closure(mixed ...$params): void $invoke the 0.6 callback body (receives do_action's params)
   * @param (\Closure(IIntegrationEvent, \Throwable): void)|null $onExhausted
   */
  public static function bind(string $hook, string $eventClass, string $subscriberId, int $priority, \Closure $invoke, ?\Closure $onExhausted = null): \Closure {
    self::$bound[$hook][$subscriberId] = [
      'id' => $subscriberId,
      'priority' => $priority,
      'seq' => ++self::$seq,
      'event' => $eventClass,
      'invoke' => $invoke,
      'onExhausted' => $onExhausted,
    ];

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
        }
        ($entry['invoke'])(...$params);
        return;
      }
      self::gate($ledger, $hook, $entry, $eventId, $params[0]);
    };
  }

  /**
   * Deterministic subscriber id for a callable: `{kind}:{name}` where name is
   * Class::method, a function name, or `Closure@path:line` (path relative to
   * ABSPATH when under it), plus `#n` for the n-th repeat on the same hook.
   */
  public static function subscriberId(string $hook, string $kind, callable $callback, ?string $name = null): string {
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
      self::gate($ledger, $hook, $entry, $eventId, $payload);
    }
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
  public static function resetForTests(): void {
    self::$bound = [];
    self::$seq = 0;
    self::$idlessNoted = [];
    self::$redeliveryScheduled = [];
  }

  /**
   * @param array{id: string, priority: int, seq: int, event: string, invoke: \Closure, onExhausted: ?\Closure} $entry
   * @param array<string, mixed> $wrapped
   */
  private static function gate(IDeliveryLedger $ledger, string $hook, array $entry, string $eventId, array $wrapped): void {
    $id = $entry['id'];
    if ($ledger->delivered($id, $eventId) || $ledger->exhausted($id, $eventId)) {
      return;
    }

    $attempts = $ledger->attempts($id, $eventId);
    if ($attempts >= self::BUDGET) {
      // Budget reached, compensation never completed: re-fire it.
      $last = new DeliveryBudgetExhausted($id, $eventId, $attempts, $ledger->lastError($id, $eventId));
      if (!self::exhaust($ledger, $hook, $entry, $eventId, $wrapped, $last)) {
        self::scheduleRedelivery($hook, $entry['event'], $eventId, $wrapped, $attempts);
      }
      return;
    }

    try {
      ($entry['invoke'])($wrapped);
    } catch (\Throwable $e) {
      $attempt = $attempts + 1;
      $ledger->markFailed($id, $eventId, $e->getMessage(), $attempt);
      Log::write(null, sprintf(
        '[ddd delivery] subscriber %s failed on %s event %s (attempt %d/%d): %s',
        $id, $hook, $eventId, $attempt, self::BUDGET, $e->getMessage()
      ));
      if ($attempt < self::BUDGET || !self::exhaust($ledger, $hook, $entry, $eventId, $wrapped, $e)) {
        self::scheduleRedelivery($hook, $entry['event'], $eventId, $wrapped, $attempt);
      }
      return;
    }

    $ledger->markDelivered($id, $eventId);
  }

  /**
   * @param array{id: string, onExhausted: ?\Closure, event: string} $entry
   * @param array<string, mixed> $wrapped
   */
  private static function exhaust(IDeliveryLedger $ledger, string $hook, array $entry, string $eventId, array $wrapped, \Throwable $last): bool {
    if ($entry['onExhausted'] !== null) {
      try {
        $class = $entry['event'];
        $event = $class::from_payload(\TangibleDDD\Application\Events\IntegrationEnvelope::unwrap($wrapped)->payload);
        ($entry['onExhausted'])($event, $last);
      } catch (\Throwable $e) {
        Log::write(null, sprintf(
          '[ddd delivery] failure callback of %s for event %s threw; compensation stays pending and is retried: %s',
          $entry['id'], $eventId, $e->getMessage()
        ));
        return false;
      }
    }
    $ledger->markExhausted($entry['id'], $eventId);
    Log::write(null, sprintf('[ddd delivery] subscriber %s exhausted its budget (%d) on %s event %s', $entry['id'], self::BUDGET, $hook, $eventId), 'error');
    return true;
  }

  /** @param array<string, mixed> $wrapped */
  private static function scheduleRedelivery(string $hook, string $eventClass, string $eventId, array $wrapped, int $attempt): void {
    if (isset(self::$redeliveryScheduled["$hook|$eventId"]) || !function_exists('as_schedule_single_action')) {
      return;
    }
    $prefix = self::prefixOf($hook, $eventClass);
    if ($prefix === null) {
      return;
    }
    self::$redeliveryScheduled["$hook|$eventId"] = true;
    as_schedule_single_action(
      time() + IntegrationDelivery::backoffSeconds($attempt),
      $prefix . '_ddd_redeliver',
      ['hook' => $hook, 'event_class' => $eventClass, 'payload' => $wrapped],
      $prefix . '-outbox'
    );
  }

  private static function ledgerFor(string $hook, string $eventClass): ?IDeliveryLedger {
    $prefix = self::prefixOf($hook, $eventClass);
    return $prefix !== null && WpSchema::isV8($prefix) ? new WpDeliveryLedger($prefix) : null;
  }

  /** The consumer prefix owning $hook: `{prefix}_integration_{name}`. */
  public static function prefixOf(string $hook, string $eventClass): ?string {
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
