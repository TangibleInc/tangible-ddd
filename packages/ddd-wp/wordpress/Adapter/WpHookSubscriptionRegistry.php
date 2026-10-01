<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\WordPress\Retries;

/**
 * The wp ISubscriptionRegistry (register 3.5), fed by SubscriptionRegistrar
 * and by ProcessRunner's register_event()/register_start(). add() binds ONE
 * add_action callback per Subscriber on the fact's legacy integration hook,
 * at the subscriber's numeric priority, so ordering (listeners 10, ignition
 * 50, resume 99, anything else where its caller put it) is WordPress' own,
 * exactly as 0.6.
 *
 * The callback is the 0.6 drain bracket (unwrap the envelope, open
 * Correlation::within(for_fact(event_id)) when the envelope carries a
 * correlation id and an event id, hydrate with from_payload(), call the
 * subscriber's handle($event, $eventId)), bound through WpLedgeredDelivery:
 * on a schema v8 consumer each subscriber is isolated and ledgered per
 * (subscriber id, event_id), retried through `{prefix}_ddd_redeliver` and
 * budgeted, with its on_exhausted compensation at the budget. Before v8 a
 * throwing callback aborts the rest of do_action, as in 0.6. The budget is
 * WpLedgeredDelivery::budget(): one attempt for a listener unless its
 * consumer opts in or its class declares #[Retries]; process ignition and
 * resume keep the core budget.
 *
 * Id-less payloads (wave1-notes): an envelope without `__event_id` (a hook
 * fired by hand, a hand-built payload) bypasses the ledger and is delivered
 * directly with event_id '' (the runner then starts without ignition dedup,
 * as 0.6 did); WpLedgeredDelivery notes it once per hook per request.
 *
 * Unresolvable facts: a fact whose owning consumer is absent has no hook
 * (IntegrationHookName); add() skips it with the usual once-per-class note.
 * Marker interfaces have no WordPress hook (O9) and are skipped with a note.
 *
 * Dedup: a second Subscriber with an id already bound is ignored while the
 * first one's callback is still on its hook (`has_action($hook, $callback)`;
 * a double boot); if that callback was removed since (remove_all_actions,
 * a reset hook table), it binds again. The newer Subscriber then replaces
 * the old one in for().
 */
final class WpHookSubscriptionRegistry implements ISubscriptionRegistry {

  /** @var array<string, array{sub: Subscriber, hook: string, callback: \Closure, seq: int}> */
  private array $bound = [];

  private int $seq = 0;

  public function add(Subscriber $s): void {
    $class = $s->event_class;

    if (interface_exists($class)) {
      IntegrationHookName::note_absent($class, 'marker subscription (no WordPress hook for a marker interface)');
      return;
    }

    $hook = IntegrationHookName::resolve($class);
    if ($hook === null) {
      IntegrationHookName::note_absent($class, self::ceremony($s));
      return;
    }

    $existing = $this->bound[$s->id] ?? null;
    if ($existing !== null && has_action($existing['hook'], $existing['callback']) !== false) {
      return;
    }

    $invoke = static function (array $payload) use ($s, $class): void {
      $envelope = IntegrationEnvelope::unwrap($payload);
      $eventId = $envelope->event_id;

      $ctx = $envelope->trace_context();
      if ($ctx !== null && $eventId !== null) {
        $ctx = $ctx->for_fact($eventId, $class);
      }

      $run = static function () use ($s, $class, $envelope, $eventId): void {
        $event = $class::from_payload($envelope->payload);
        ($s->handle)($event, (string) ($eventId ?? ''));
      };

      $ctx !== null ? Correlation::within($ctx, $run) : $run();
    };
    $callback = WpLedgeredDelivery::bind($hook, $class, $s->id, $s->priority, $invoke, $s->on_exhausted, self::declared($s)?->attempts());
    add_action($hook, $callback, $s->priority, 1);

    $this->bound[$s->id] = ['sub' => $s, 'hook' => $hook, 'callback' => $callback, 'seq' => ++$this->seq];
  }

  public function for(string $eventClass): array {
    $matching = array_filter(
      $this->bound,
      static fn (array $e) => is_a($eventClass, $e['sub']->event_class, true)
    );
    usort($matching, static fn (array $a, array $b) => [$a['sub']->priority, $a['seq']] <=> [$b['sub']->priority, $b['seq']]);

    return array_map(static fn (array $e) => $e['sub'], $matching);
  }

  /**
   * The #[Retries] of a SubscriptionRegistrar listener: its id is
   * `listener:<class>` (register 3.5), so the class is read off the id.
   */
  private static function declared(Subscriber $s): ?Retries {
    if (!str_starts_with($s->id, 'listener:')) {
      return null;
    }
    $class = substr($s->id, strlen('listener:'));
    return class_exists($class) ? Retries::of($class) : null;
  }

  private static function ceremony(Subscriber $s): string {
    return match (true) {
      str_starts_with($s->id, 'resume:') => 'process resume',
      str_starts_with($s->id, 'ignition:') => 'process ignition',
      str_starts_with($s->id, 'listener:') => 'integration_listener',
      default => 'subscription',
    };
  }
}
