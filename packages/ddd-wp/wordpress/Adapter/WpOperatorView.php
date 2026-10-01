<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp operator view (register 3.10, 5.1, D9): one read-only list over
 * every retry layer of one consumer, attempts against budget, the last
 * error and the repairs that apply. Backs `wp ddd ops`.
 *
 * Layers (the core `Runtime\Ops\Layer` values):
 * - relay:    DLQ rows (budget = max_attempts) and pending rows retrying;
 * - delivery: ledger pairs `failed` or `exhausted` (budget 5);
 * - wakeup:   intents that failed at least once or died while firing (budget 10);
 * - process:  stranded `scheduled`/`running` rows and quarantined rows.
 *
 * Schema v8 only for the delivery, wakeup and process layers; the relay
 * layer works on the 0.6 schema too. Rows are plain arrays with the
 * OperatorItem fields (layer, consumer, key, attempts, budget, last_error,
 * first_seen, repair_actions) until core's IOperatorView lands
 * (change request WP8-2).
 */
final class WpOperatorView {

  public const LAYERS = ['relay', 'delivery', 'wakeup', 'process'];

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly ?IClock $clock = null,
  ) {}

  /** @return list<array{layer: string, consumer: string, key: string, attempts: int, budget: int, last_error: ?string, first_seen: string, repair_actions: list<string>}> */
  public function list(?string $layer = null, int $limit = 100): array {
    if ($layer !== null && !in_array($layer, self::LAYERS, true)) {
      throw new \InvalidArgumentException("Unknown layer $layer (one of " . implode(', ', self::LAYERS) . ')');
    }
    $rows = [];
    foreach (self::LAYERS as $l) {
      if ($layer === null || $layer === $l) {
        array_push($rows, ...$this->{$l}($limit));
      }
    }
    return array_slice($rows, 0, max(0, $limit));
  }

  private function relay(int $limit): array {
    $db = self::db();
    $out = [];
    foreach ((array) $db->get_results($db->prepare(
      "SELECT id, event_id, attempts, final_error, moved_at FROM `{$this->config->table('integration_dlq')}` ORDER BY id DESC LIMIT %d",
      $limit
    )) as $r) {
      $out[] = $this->item('relay', 'dlq#' . $r->id . ' ' . $r->event_id, (int) $r->attempts, (int) $r->attempts, $r->final_error, (string) $r->moved_at, ['replay', 'discard']);
    }
    foreach ((array) $db->get_results($db->prepare(
      "SELECT event_id, attempts, max_attempts, last_error, created_at FROM `{$this->config->table('integration_outbox')}`
       WHERE status = 'pending' AND attempts > 0 ORDER BY id DESC LIMIT %d",
      $limit
    )) as $r) {
      $out[] = $this->item('relay', (string) $r->event_id, (int) $r->attempts, (int) $r->max_attempts, $r->last_error, (string) $r->created_at, ['retry']);
    }
    return $out;
  }

  private function delivery(int $limit): array {
    if (!WpSchema::isV8($this->config)) {
      return [];
    }
    return array_map(fn (array $r) => $this->item(
      'delivery',
      $r['subscriber_id'] . ' @ ' . $r['event_id'],
      $r['attempts'],
      IntegrationDelivery::DEFAULT_BUDGET,
      $r['last_error'],
      $r['updated_at'],
      $r['status'] === 'exhausted' ? [] : ['redeliver'],
    ), (new WpDeliveryLedger($this->config->prefix(), $this->clock))->problems($limit));
  }

  private function wakeup(int $limit): array {
    if (!WpSchema::isV8($this->config)) {
      return [];
    }
    $db = self::db();
    $rows = $db->get_results($db->prepare(
      "SELECT idempotency_key, attempts, last_error, created_at FROM `{$this->config->table('ddd_wakeups')}`
       WHERE status IN ('pending', 'firing') AND attempts > 0 ORDER BY updated_at DESC LIMIT %d",
      $limit
    ));
    return array_map(fn (object $r) => $this->item('wakeup', (string) $r->idempotency_key, (int) $r->attempts, 10, $r->last_error, (string) $r->created_at, ['reproject']), is_array($rows) ? $rows : []);
  }

  private function process(int $limit): array {
    if (!WpSchema::isV8($this->config)) {
      return [];
    }
    $out = [];
    $store = new WpdbProcessStore(new ProcessRepository($this->config), $this->config, $this->clock);
    foreach (array_slice($store->findStranded($this->now()), 0, $limit) as $s) {
      $out[] = $this->item('process', "#{$s->processId} {$s->processClass} ({$s->status}, step {$s->stepIndex})", 0, 0, 'stranded', $s->updatedAt->format('Y-m-d H:i:s'),
        $s->status === 'running' ? ['resume-stranded', 'fail-stranded'] : ['continue']);
    }
    $db = self::db();
    foreach ((array) $db->get_results($db->prepare(
      "SELECT id, process_class, quarantine_reason, updated_at FROM `{$this->config->table('long_processes')}`
       WHERE quarantine_reason IS NOT NULL ORDER BY id DESC LIMIT %d",
      $limit
    )) as $r) {
      $out[] = $this->item('process', "#{$r->id} {$r->process_class} (quarantined)", 0, 0, $r->quarantine_reason, (string) $r->updated_at, []);
    }
    return $out;
  }

  /** @param list<string> $repairs */
  private function item(string $layer, string $key, int $attempts, int $budget, mixed $error, string $firstSeen, array $repairs): array {
    return [
      'layer' => $layer,
      'consumer' => $this->config->prefix(),
      'key' => $key,
      'attempts' => $attempts,
      'budget' => $budget,
      'last_error' => $error === null ? null : (string) $error,
      'first_seen' => $firstSeen,
      'repair_actions' => $repairs,
    ];
  }

  private function now(): \DateTimeImmutable {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now();
  }

  private static function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
