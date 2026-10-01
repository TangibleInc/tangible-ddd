<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use TangibleDDD\Runtime\Outbox\OutboxRecord;

/**
 * OutboxRecord ↔ `ddd_outbox` / `ddd_dlq` row mapping shared by the store,
 * the administration and the operator view.
 *
 * @internal
 */
final class OutboxRows {

  /** Columns that ddd_outbox and ddd_dlq share, copied verbatim on dead-letter and replay. */
  public const SHARED = [
    'event_id', 'event_type', 'event_class', 'integration_action', 'correlation_id', 'sequence', 'command_id',
    'payload', 'payload_signature', 'signature_json', 'is_unique', 'max_attempts', 'due_at', 'blog_id',
  ];

  /** @param array<string, mixed> $row */
  public static function record(array $row): OutboxRecord {
    $signature = $row['signature_json'] ?? null;
    return new OutboxRecord(
      event_id: (string) $row['event_id'],
      event_type: (string) $row['event_type'],
      integration_action: (string) $row['integration_action'],
      correlation_id: $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
      sequence: $row['sequence'] === null ? null : (int) $row['sequence'],
      command_id: $row['command_id'] === null ? null : (string) $row['command_id'],
      payload: (array) json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR),
      due_at: Utc::fromDb((string) $row['due_at']),
      is_unique: (bool) (int) $row['is_unique'],
      payload_signature: $signature === null ? null : (array) json_decode((string) $signature, true, 512, JSON_THROW_ON_ERROR),
      max_attempts: (int) $row['max_attempts'],
      blog_id: $row['blog_id'] === null ? null : (int) $row['blog_id'],
    );
  }

  /** @return array<string, mixed> the SHARED columns of a fresh row */
  public static function columns(OutboxRecord $r, ?string $eventClass): array {
    $signatureJson = $r->payload_signature === null ? null : self::json(self::canonical($r->payload_signature));
    return [
      'event_id' => $r->event_id,
      'event_type' => $r->event_type,
      'event_class' => $eventClass,
      'integration_action' => $r->integration_action,
      'correlation_id' => $r->correlation_id,
      'sequence' => $r->sequence,
      'command_id' => $r->command_id,
      'payload' => self::json($r->payload),
      'payload_signature' => $signatureJson === null ? null : hash('sha256', $signatureJson),
      'signature_json' => $signatureJson,
      'is_unique' => $r->is_unique,
      'max_attempts' => $r->max_attempts,
      'due_at' => Utc::toDb($r->due_at),
      'blog_id' => $r->blog_id,
    ];
  }

  /** @param array<string, mixed> $row @return list<mixed> the SHARED values of a stored row, in SHARED order */
  public static function sharedValues(array $row): array {
    $out = [];
    foreach (self::SHARED as $column) {
      $value = $row[$column];
      $out[] = in_array($column, ['sequence', 'is_unique', 'max_attempts', 'blog_id'], true) && $value !== null ? (int) $value : $value;
    }
    return $out;
  }

  /** @param array<string, mixed> $columns */
  public static function insertSql(string $table, array $columns): string {
    $names = implode(', ', array_map(static fn (string $c) => "`$c`", array_keys($columns)));
    $marks = implode(', ', array_fill(0, count($columns), '?'));
    return "INSERT INTO `$table` ($names) VALUES ($marks)";
  }

  public static function json(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

  /** Recursively key-sorted, so signatures equal as PHP `==` arrays hash alike. */
  private static function canonical(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      ksort($value);
    }
    return array_map(self::canonical(...), $value);
  }
}
