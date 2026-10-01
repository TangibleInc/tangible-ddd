<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Runtime\Codec\PayloadTooLarge;
use TangibleDDD\Runtime\Codec\UnencodablePayload;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IFactObserver;
use TangibleDDD\Runtime\NullFactObserver;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\SystemClock;

/**
 * Outbox-backed integration event bus.
 *
 * Instead of handing facts to a transport directly, this bus writes them to
 * the transactional outbox inside the publishing command's transaction. A
 * separate relay (OutboxProcessor) moves them to the transport, so a fact is
 * never lost between the commit and the enqueue.
 *
 * Two forms (register 1.4, CONF-2):
 *
 * - Port form: `new OutboxIntegrationEventBus(null, $config, $observer,
 *   $clock, $store, $outboxConfig)`. Each fact becomes an OutboxRecord
 *   appended to the IOutboxStore, with an ABSOLUTE UTC `due_at` = clock now
 *   + delay(), computed once here (bug 3); the store cancels older unleased
 *   duplicates of an is_unique fact.
 * - 0.6 form: `new OutboxIntegrationEventBus($repository, $config)`, unchanged
 *   for shipped containers: IOutboxRepository::cancel_duplicates() then
 *   write(), which compute the 0.6 row themselves.
 *
 * Both keep the 0.6 stamps: the Trajectory→Fact guard, the ambient story or
 * a fresh one, the raiser edge (the act it was announced from, else null),
 * the next story position, then PublishedFacts::mark().
 *
 * The IFactObserver (the touches indexer on WordPress) sees every published
 * fact with its record; it comes from the constructor, else the host's
 * per-consumer one (HostDefaults::for), else NullFactObserver (O4). An
 * observer error is caught and logged: observers never break publication.
 *
 * Constructor types stay autowire-friendly (no unions): consumers' runtime
 * containers autowire this class.
 */
final class OutboxIntegrationEventBus implements IIntegrationEventBus {

  public function __construct(
    private readonly ?IOutboxRepository $outbox,
    private readonly ?IDDDConfig $config = null,
    private readonly ?IFactObserver $observer = null,
    private readonly ?IClock $clock = null,
    private readonly ?IOutboxStore $store = null,
    private readonly ?OutboxConfig $outbox_config = null,
  ) {
    if ($outbox === null && $store === null) {
      throw new \InvalidArgumentException('OutboxIntegrationEventBus needs an IOutboxStore (port form) or an IOutboxRepository (0.6 form).');
    }
  }

  public function publish(IIntegrationEvent $event): void {
    $cause = Correlation::peek()?->cause;

    // Trajectory→Fact guard: a saga step announcing directly would write an
    // orphan fact (no raiser edge). Steps sequence commands; handlers
    // announce. Read off the ambient cause: inside a wake the cause is the
    // trajectory; the ground contact (step → command → handler announces)
    // runs inside an ACT scope and passes.
    if ($cause?->kind === Kind::Trajectory) {
      throw new FactPublishedInsideProcess(get_class($event), $cause->id);
    }

    // The story — a fact announced from a flat context (wp ddd announce)
    // starts its own, minted without touching the ambient — and the raiser
    // edge: a fact's parent is the ACT it was announced from, null for the
    // sanctioned command-less doors.
    $correlation = Correlation::peek()?->correlation_id ?? Uuid::v4();
    $raiser = $cause?->kind === Kind::Act ? $cause->id : null;

    $record = $this->store !== null
      ? $this->append($event, $correlation, $raiser)
      : $this->write_legacy($event, $correlation, $raiser);

    if ($record === null) {
      return;
    }

    // Mark the instance as published (0.3): facts carry no identity slots —
    // the at-rest identity is the outbox row, the in-flight identity is the
    // envelope. PublishedFacts is the re-raise guard's memory.
    PublishedFacts::mark($event, $record->event_id);

    // Fact bookkeeping happens where facts happen (0.5.2): every
    // publication lane (act, announce, flat) passes here. Never throws.
    $this->observe($event, $record);
  }

  /** Port form: one record, absolute due time, appended inside the ambient transaction. */
  private function append(IIntegrationEvent $event, string $correlation, ?string $raiser): OutboxRecord {
    $payload = $event->integration_payload();
    $this->guard_payload($event, $payload);
    $delay = max(0, $event->delay());

    $record = new OutboxRecord(
      event_id: Uuid::v4(),
      event_type: $event::name(),
      integration_action: $event::integration_action(),
      correlation_id: $correlation,
      sequence: Correlation::peek() !== null ? Correlation::next_sequence() : 1,
      command_id: $raiser,
      payload: $payload,
      due_at: $this->clock()->now()->modify("+{$delay} seconds"),
      is_unique: $event->is_unique(),
      payload_signature: $event->is_unique() ? $payload : null,
      max_attempts: ($this->outbox_config ?? new OutboxConfig())->max_attempts,
      event_class: get_class($event),
    );

    $this->store->append($record);

    return $record;
  }

  /**
   * 0.6 form: the repository writes its own row (scheduled_at, sequence,
   * blog stamp). The record handed to the observer mirrors it.
   */
  private function write_legacy(IIntegrationEvent $event, string $correlation, ?string $raiser): ?OutboxRecord {
    // No D6 guard here: the 0.6 repositories encode with their own rules
    // (the WordPress encoder repairs invalid UTF-8), and shipped consumers keep
    // that behaviour (R2).

    // Handle is_unique: cancel existing pending events of same type
    if ($event->is_unique()) {
      $this->outbox->cancel_duplicates($event::name(), $event->integration_payload());
    }

    $event_id = $this->outbox->write($event, $correlation, $raiser);
    if (!is_string($event_id) || $event_id === '') {
      return null;
    }

    $delay = max(0, $event->delay());
    return new OutboxRecord(
      event_id: $event_id,
      event_type: $event::name(),
      integration_action: $event::integration_action(),
      correlation_id: $correlation,
      sequence: null,
      command_id: $raiser,
      payload: $event->integration_payload(),
      due_at: $this->clock()->now()->modify("+{$delay} seconds"),
      is_unique: $event->is_unique(),
    );
  }

  /**
   * D6, at append in the port form, before commit: the payload must encode as JSON
   * (UnencodablePayload otherwise; a binary string belongs in a LargeString
   * field), and its encoded size must stay within
   * OutboxConfig::$max_payload_bytes (PayloadTooLarge; 0 = no cap).
   *
   * @param array<string, mixed> $payload
   */
  private function guard_payload(IIntegrationEvent $event, array $payload): void {
    try {
      $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (\JsonException $e) {
      throw new UnencodablePayload($event::name(), $e->getMessage());
    }

    $cap = ($this->outbox_config ?? new OutboxConfig())->max_payload_bytes;
    if ($cap > 0 && strlen($json) > $cap) {
      throw new PayloadTooLarge(sprintf('The payload of %s', $event::name()), strlen($json), $cap);
    }
  }

  private function observe(IIntegrationEvent $event, OutboxRecord $record): void {
    try {
      $this->observer()->observe($event, $record);
    } catch (\Throwable $e) {
      Log::write(null, sprintf(
        '[ddd outbox] fact observer failed on %s %s (publication unaffected): %s',
        $event::name(), $record->event_id, $e->getMessage()
      ));
    }
  }

  private function observer(): IFactObserver {
    if ($this->observer !== null) {
      return $this->observer;
    }
    return ($this->config !== null ? HostDefaults::for(IFactObserver::class, $this->config) : HostDefaults::get(IFactObserver::class))
      ?? new NullFactObserver();
  }

  private function clock(): IClock {
    return $this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock();
  }
}
