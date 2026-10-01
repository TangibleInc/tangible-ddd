<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Support\Log;

/**
 * Transitional wave-2 ISubscriptionRegistry on WordPress (register 3.5,
 * section 8), fed by SubscriptionRegistrar and by ProcessRunner's
 * register_event()/register_start(). add() binds ONE add_action callback per
 * Subscriber on the fact's legacy integration hook, at the subscriber's
 * numeric priority, so ordering (listeners 10, ignition 50, resume 99,
 * anything else where its caller put it) is WordPress' own, exactly as 0.6.
 *
 * The callback is the 0.6 drain bracket: unwrap the envelope, open
 * Correlation::within(for_fact(event_id)) when the envelope carries a
 * correlation id and an event id, hydrate with from_payload(), and call the
 * subscriber's handle($event, $eventId).
 *
 * No IntegrationDelivery and no ledger before schema v8 (wave 3), so a
 * throwing callback aborts the rest of do_action, as in 0.6.
 *
 * Id-less payloads (wave1-notes): an envelope without `__event_id` (a hook
 * fired by hand, a hand-built payload) is still delivered, unledgered, with
 * eventId '' (the runner then starts without ignition dedup, as 0.6 did),
 * and a notice is logged once per hook per request.
 *
 * Unresolvable facts: a fact whose owning consumer is absent has no hook
 * (IntegrationHookName); add() skips it with the usual once-per-class note.
 * Marker interfaces have no WordPress hook (O9) and are skipped with a note.
 *
 * Dedup: a second Subscriber with an id already bound is ignored while its
 * hook still has callbacks (a double boot); if the hook table was cleared
 * since, it binds again.
 */
final class WpHookSubscriptionRegistry implements ISubscriptionRegistry {

  /** @var array<string, array{sub: Subscriber, hook: string, seq: int}> */
  private array $bound = [];

  private int $seq = 0;

  /** @var array<string, true> hooks already noted for an id-less payload this request */
  private array $idlessNoted = [];

  public function add(Subscriber $s): void {
    $class = $s->eventClassOrMarker;

    if (interface_exists($class)) {
      IntegrationHookName::note_absent($class, 'marker subscription (no WordPress hook for a marker interface)');
      return;
    }

    $hook = IntegrationHookName::resolve($class);
    if ($hook === null) {
      IntegrationHookName::note_absent($class, self::ceremony($s));
      return;
    }

    if (isset($this->bound[$s->id]) && has_action($this->bound[$s->id]['hook'])) {
      return;
    }

    add_action($hook, function (array $payload) use ($s, $class, $hook): void {
      $envelope = IntegrationEnvelope::unwrap($payload);
      $eventId = $envelope->event_id;
      if ($eventId === null || $eventId === '') {
        $this->noteIdless($hook);
      }

      $ctx = $envelope->trace_context();
      if ($ctx !== null && $eventId !== null) {
        $ctx = $ctx->for_fact($eventId, $class);
      }

      $run = static function () use ($s, $class, $envelope, $eventId): void {
        $event = $class::from_payload($envelope->payload);
        ($s->handle)($event, (string) ($eventId ?? ''));
      };

      $ctx !== null ? Correlation::within($ctx, $run) : $run();
    }, $s->priority, 1);

    $this->bound[$s->id] = ['sub' => $s, 'hook' => $hook, 'seq' => ++$this->seq];
  }

  public function for(string $eventClass): array {
    $matching = array_filter(
      $this->bound,
      static fn (array $e) => is_a($eventClass, $e['sub']->eventClassOrMarker, true)
    );
    usort($matching, static fn (array $a, array $b) => [$a['sub']->priority, $a['seq']] <=> [$b['sub']->priority, $b['seq']]);

    return array_map(static fn (array $e) => $e['sub'], $matching);
  }

  private function noteIdless(string $hook): void {
    if (isset($this->idlessNoted[$hook])) {
      return;
    }
    $this->idlessNoted[$hook] = true;
    Log::write(null, sprintf(
      '[DDD Integration] %s fired without __event_id: delivered to DDD subscribers directly, without ledger or ignition dedup',
      $hook
    ), 'notice');
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
