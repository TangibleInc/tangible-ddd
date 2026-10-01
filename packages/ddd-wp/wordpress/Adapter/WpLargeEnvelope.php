<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Events\IntegrationEnvelope;

/**
 * By-reference envelopes for facts over Action Scheduler's args limit (D6,
 * `codec.large-payload` on wp).
 *
 * Action Scheduler refuses an action whose JSON-encoded args exceed 8000
 * bytes (ActionScheduler_DBStore::$max_args_length; `extended_args` is a
 * VARCHAR(8000)). 0.6 therefore could not relay such a fact at all. A wrapped
 * envelope over the limit is scheduled instead as its journey keys
 * (`__correlation_id`, `__sequence`, `__event_id`) plus MARKER => ['prefix',
 * 'bytes']: a pointer to the payload in the consumer's
 * `{prefix}_integration_outbox` row, which the relay keeps (`completed`).
 * Small envelopes keep the 0.6 action shape byte for byte.
 *
 * resolve() is applied by WpLedgeredDelivery to every DDD-registered
 * callback, inside its ledger gate, so a payload that cannot be loaded (the
 * row was purged or deleted) fails that subscriber's attempt like any other
 * error and is retried / budgeted. Raw `add_action` callbacks receive the
 * reference form (outside the guarantee, as documented). Redelivery args
 * keep the reference form, so they also fit.
 *
 * Rollback (register 7.3): a 0.6 winner cannot resolve a reference; a
 * pending by-reference action is N-only, like `{prefix}_ddd_redeliver`.
 * `wp ddd drain --before-rollback` runs due integration actions too. The
 * outbox row must outlive its action: purge only removes `completed` rows
 * older than the retention window, so a by-reference fact delayed beyond
 * that window can lose its payload (logged as a failed attempt).
 */
final class WpLargeEnvelope {

  public const MARKER = '__ddd_outbox_ref';

  /** ActionScheduler_DBStore::$max_args_length */
  public const ARGS_LIMIT = 8000;

  private const JOURNEY = ['__correlation_id', '__sequence', '__event_id'];

  /**
   * The args-safe form of $wrapped for an action on $integrationAction
   * (`{prefix}_integration_{event_type}`).
   *
   * @param array<string, mixed> $wrapped
   * @return array<string, mixed>
   */
  public static function forTransport(array $wrapped, string $integrationAction, string $eventType): array {
    $encoded = wp_json_encode([$wrapped]);
    if (!is_string($encoded) || strlen($encoded) <= self::ARGS_LIMIT) {
      return $wrapped;
    }
    $eventId = $wrapped['__event_id'] ?? null;
    $suffix = '_integration_' . $eventType;
    $prefix = str_ends_with($integrationAction, $suffix) ? substr($integrationAction, 0, -strlen($suffix)) : '';
    if (!is_string($eventId) || $eventId === '' || preg_match('/^[a-z0-9_]+$/', $prefix) !== 1) {
      return $wrapped; // cannot point at a row: Action Scheduler refuses it, as in 0.6
    }

    $ref = [];
    foreach (self::JOURNEY as $key) {
      $ref[$key] = $wrapped[$key] ?? null;
    }
    $ref[self::MARKER] = ['prefix' => $prefix, 'bytes' => strlen($encoded)];
    return $ref;
  }

  /** @param array<string, mixed> $wrapped */
  public static function isReference(array $wrapped): bool {
    return isset($wrapped[self::MARKER]) && is_array($wrapped[self::MARKER]);
  }

  /**
   * The full wrapped envelope behind $wrapped (unchanged when it is not a
   * reference).
   *
   * @param array<string, mixed> $wrapped
   * @return array<string, mixed>
   * @throws \RuntimeException when the outbox row or its payload is gone
   */
  public static function resolve(array $wrapped): array {
    if (!self::isReference($wrapped)) {
      return $wrapped;
    }
    $prefix = (string) ($wrapped[self::MARKER]['prefix'] ?? '');
    $eventId = (string) ($wrapped['__event_id'] ?? '');
    if (preg_match('/^[a-z0-9_]+$/', $prefix) !== 1 || $eventId === '') {
      throw new \RuntimeException('Malformed by-reference envelope: ' . (string) wp_json_encode($wrapped[self::MARKER]));
    }

    /** @var \wpdb $db */
    $db = $GLOBALS['wpdb'];
    $table = $db->prefix . $prefix . '_integration_outbox';
    $payload = $db->get_var($db->prepare("SELECT payload FROM `$table` WHERE event_id = %s", $eventId));
    $decoded = is_string($payload) ? json_decode($payload, true) : null;
    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf(
        'The payload of by-reference fact %s is not in its outbox row %s (purged or deleted?)%s',
        $eventId,
        $table,
        $db->last_error !== '' ? ': ' . $db->last_error : ''
      ));
    }

    return IntegrationEnvelope::wrap(
      $decoded,
      isset($wrapped['__correlation_id']) ? (string) $wrapped['__correlation_id'] : null,
      isset($wrapped['__sequence']) ? (int) $wrapped['__sequence'] : null,
      $eventId,
    );
  }
}
