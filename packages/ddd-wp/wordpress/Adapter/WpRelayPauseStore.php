<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp IRelayPauseStore (register 3.4, C25; schema v8): one row per
 * (holder, selector) in `{prefix}_ddd_relay_pauses`, selectors exact, `*`
 * or globs (`acme_order_*`), `until_at` NULL = until released.
 *
 * The 0.6 option `{prefix}_outbox_pauses` (holder => {selector, until}) is
 * READ beside the rows until it is drained: a hold set by a 0.6 copy (or
 * before the upgrade) keeps pausing the v8 relay, and holds written here
 * never touch the option, so a 0.6 winner after a rollback sees only its
 * own holds. The option's selectors are matched with the same glob rule (a
 * superset of 0.6's exact-or-`*` match).
 *
 * Errors: hold()/release() throw OutboxWriteFailed on a wpdb failure. A
 * failed read of the rows fails CLOSED: selectors() / exclusion()
 * throw \RuntimeException (so WpdbOutboxStore::claim() throws and the tick
 * reports it), and is_paused() never throws but answers true.
 */
final class WpRelayPauseStore implements IRelayPauseStore {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly ?IClock $clock = null,
  ) {}

  public function hold(string $holder, string $selector, ?\DateTimeImmutable $until): void {
    $db = self::db();
    $created = $this->now()->format('Y-m-d H:i:s');
    $sql = "INSERT INTO `{$this->table()}` (holder, selector, until_at, created_at) VALUES (%s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE until_at = VALUES(until_at)";
    $ok = $until === null
      ? $db->query($db->prepare(str_replace('%s, %s, %s, %s', '%s, %s, NULL, %s', $sql), $holder, $selector, $created))
      : $db->query($db->prepare($sql, $holder, $selector, $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $created));
    if ($ok === false) {
      throw new OutboxWriteFailed("Relay pause hold($holder, $selector) failed: " . (string) $db->last_error);
    }
  }

  public function release(string $holder, ?string $selector = null): void {
    $db = self::db();
    $ok = $selector === null
      ? $db->delete($this->table(), ['holder' => $holder])
      : $db->delete($this->table(), ['holder' => $holder, 'selector' => $selector]);
    if ($ok === false) {
      throw new OutboxWriteFailed("Relay pause release($holder) failed: " . (string) $db->last_error);
    }
  }

  public function is_paused(string $eventType, \DateTimeImmutable $now): bool {
    try {
      $selectors = $this->selectors($now);
    } catch (\RuntimeException $e) {
      // Fail closed: a hold that cannot be read is assumed to be there.
      \TangibleDDD\Runtime\Support\Log::write(null, "[ddd relay] {$e->getMessage()}; treating $eventType as paused", 'error');
      return true;
    }
    foreach ($selectors as $selector) {
      if ($selector === '*' || $selector === $eventType || fnmatch($selector, $eventType)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Every selector holding the relay at $now: unexpired rows plus the
   * unexpired holds of the 0.6 option.
   *
   * @return list<string>
   * @throws \RuntimeException when the pause rows cannot be read: the relay
   *         fails closed (claim() throws, is_paused() says paused) rather
   *         than relaying event types that may be held
   */
  public function selectors(\DateTimeImmutable $now): array {
    $db = self::db();
    $at = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $suppress = $db->suppress_errors(true); // reported below, not printed
    $rows = $db->get_col($db->prepare(
      "SELECT selector FROM `{$this->table()}` WHERE until_at IS NULL OR until_at > %s",
      $at
    ));
    $db->suppress_errors($suppress);
    if ($db->last_error !== '') {
      if (WpSchema::is_v8($this->config)) {
        throw new \RuntimeException("Relay pause read failed on {$this->table()}: {$db->last_error}");
      }
      // Before the v8 migration the table may not exist yet (no hold can
      // be in it then): the 0.6 option is the whole truth.
      $rows = [];
    }

    $selectors = is_array($rows) ? array_map('strval', $rows) : [];

    $legacy = get_option($this->config->option('outbox_pauses'), []);
    foreach (is_array($legacy) ? $legacy : [] as $hold) {
      $until = (int) ($hold['until'] ?? -1);
      if ($until !== -1 && $until <= $now->getTimestamp()) {
        continue;
      }
      $selector = (string) ($hold['selector'] ?? '');
      if ($selector !== '') {
        $selectors[] = $selector;
      }
    }

    return array_values(array_unique($selectors));
  }

  /**
   * The SQL condition that excludes paused event types, for the outbox
   * claim: [sql, params]; sql is '' when nothing is paused and null when a
   * wildcard holds everything.
   *
   * @return array{0: ?string, 1: list<string>}
   */
  public function exclusion(\DateTimeImmutable $now): array {
    $selectors = $this->selectors($now);
    if (in_array('*', $selectors, true)) {
      return [null, []];
    }
    if ($selectors === []) {
      return ['', []];
    }
    $db = self::db();
    $like = array_map(static fn (string $s) => strtr($db->esc_like($s), ['*' => '%', '?' => '_']), $selectors);
    return [' AND NOT (' . implode(' OR ', array_fill(0, count($like), 'event_type LIKE %s')) . ')', $like];
  }

  private function table(): string {
    return $this->config->table('ddd_relay_pauses');
  }

  private function now(): \DateTimeImmutable {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now();
  }

  private static function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
