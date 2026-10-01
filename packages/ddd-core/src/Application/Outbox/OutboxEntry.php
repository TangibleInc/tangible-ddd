<?php

namespace TangibleDDD\Application\Outbox;

/**
 * Value object representing an outbox entry.
 */
final class OutboxEntry {

  public function __construct(
    public readonly ?int $id,
    public readonly string $event_id,
    public readonly string $event_type,
    public readonly string $integration_action,
    public readonly string $message_kind,
    public readonly string $transport,
    public readonly ?string $queue,
    public readonly int $payload_bytes,
    public readonly string $correlation_id,
    public readonly int $sequence,
    public readonly ?string $command_id,
    public readonly array $payload,
    public readonly int $delay_seconds,
    public readonly string $scheduled_at,
    public readonly bool $is_unique,
    public readonly string $status,
    public readonly int $attempts,
    public readonly int $max_attempts,
    public readonly ?string $next_attempt_at,
    public readonly ?string $locked_until,
    public readonly ?string $locked_by,
    public readonly ?string $last_error,
    public readonly ?array $error_history,
    public readonly string $created_at,
    public readonly ?string $processed_at,
    public readonly int $blog_id,
  ) {}

  public static function from_row(object $row): self {
    return new self(
      id: (int) $row->id,
      event_id: $row->event_id,
      event_type: $row->event_type,
      integration_action: $row->integration_action,
      message_kind: $row->message_kind ?? 'event',
      transport: $row->transport ?? 'action_scheduler',
      queue: $row->queue ?? null,
      payload_bytes: (int) ($row->payload_bytes ?? 0),
      correlation_id: $row->correlation_id,
      sequence: (int) ($row->sequence ?? 0),
      command_id: $row->command_id ?? null,
      payload: json_decode($row->payload, true) ?: [],
      delay_seconds: (int) ($row->delay_seconds ?? 0),
      scheduled_at: $row->scheduled_at,
      is_unique: (bool) ($row->is_unique ?? false),
      status: $row->status,
      attempts: (int) ($row->attempts ?? 0),
      max_attempts: (int) ($row->max_attempts ?? 5),
      next_attempt_at: $row->next_attempt_at ?? null,
      locked_until: $row->locked_until ?? null,
      locked_by: $row->locked_by ?? null,
      last_error: $row->last_error ?? null,
      error_history: isset($row->error_history) ? (json_decode($row->error_history, true) ?: null) : null,
      created_at: $row->created_at,
      processed_at: $row->processed_at ?? null,
      blog_id: (int) ($row->blog_id ?? 1),
    );
  }

  /**
   * The 0.6 entry shape of a leased port record, for the infrastructure
   * signals (OutboxDeadLettered, OutboxAttemptFailed, FactDeliveredUnheard)
   * the core relay emits. Port records have no integer row id (null) and no
   * relative delay (0; due_at is absolute). Times are UTC 'Y-m-d H:i:s'.
   */
  public static function from_claim(\TangibleDDD\Runtime\Outbox\Claim $claim, string $status = 'pending', ?string $last_error = null): self {
    $r = $claim->record;
    $json = json_encode($r->payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return new self(
      id: null,
      event_id: $r->event_id,
      event_type: $r->event_type,
      integration_action: $r->integration_action,
      message_kind: 'event',
      transport: 'port',
      queue: null,
      payload_bytes: is_string($json) ? strlen($json) : 0,
      correlation_id: (string) $r->correlation_id,
      sequence: (int) ($r->sequence ?? 0),
      command_id: $r->command_id,
      payload: $r->payload,
      delay_seconds: 0,
      scheduled_at: $r->due_at->format('Y-m-d H:i:s'),
      is_unique: $r->is_unique,
      status: $status,
      attempts: $claim->attempts,
      max_attempts: $r->max_attempts,
      next_attempt_at: null,
      locked_until: $claim->lease_until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
      locked_by: $claim->token,
      last_error: $last_error,
      error_history: null,
      created_at: '',
      processed_at: null,
      blog_id: $r->blog_id ?? 1,
    );
  }
}
