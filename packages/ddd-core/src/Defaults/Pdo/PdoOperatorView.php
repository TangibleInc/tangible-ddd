<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * The pdo host's one failure view (register 3.10, 5.1, D9): read-only, one
 * list across the layers, attempts against budget, for the host to render
 * (no UI here). Repairs are the core repair commands; `repair_actions` names
 * the ones that apply.
 *
 * Layers (values of the core `Layer` enum):
 * - `relay`: DLQ rows (retry | replay | discard) and `pending` outbox rows
 *   that already failed a submission (retry). Budget: the row's max_attempts.
 * - `delivery`: ledger pairs not delivered with failed attempts or the
 *   exhausted marker (state `retrying` | `exhausted`), and `deliver` jobs
 *   that failed. Budget: $deliveryBudget (5.1: 5).
 * - `wakeup`: wakeup intents that failed (LockNotAcquired, DB errors).
 *   Budget: $wakeBudget (5.1: 10).
 * - `process`: stranded processes (PdoProcessStore::findStranded; repairs
 *   resume_stranded | fail_stranded) and quarantined ones (state
 *   `quarantined`, last_error = quarantine_reason).
 *
 * Each item is an array with the register's OperatorItem fields
 * (layer, consumer, key, attempts, budget, last_error, first_seen,
 * repair_actions) plus `detail`. When core ships IOperatorView/OperatorItem
 * this class adopts them (wave3-pdo-adapters CR-PDO-4).
 */
final class PdoOperatorView {

  public const LAYERS = ['relay', 'delivery', 'wakeup', 'process', 'workflow', 'transport'];

  private readonly PrefixedTableNames $tables;
  private readonly IClock $clock;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly string $consumer,
    private readonly string $tablePrefix = '',
    ?IClock $clock = null,
    private readonly int $deliveryBudget = 5,
    private readonly int $wakeBudget = 10,
    private readonly int $strandedAfterSeconds = 900,
  ) {
    $this->tables = new PrefixedTableNames($tablePrefix);
    $this->clock = $clock ?? new SystemClock();
  }

  /**
   * @return list<array{layer: string, consumer: string, key: string, attempts: int, budget: int,
   *   last_error: ?string, first_seen: \DateTimeImmutable, repair_actions: list<string>, detail: array<string, mixed>}>
   */
  public function list(?string $layer = null, int $limit = 100): array {
    if ($layer !== null && !in_array($layer, self::LAYERS, true)) {
      throw new \InvalidArgumentException("Unknown operator layer '$layer' (one of " . implode(', ', self::LAYERS) . ')');
    }
    $limit = max(0, $limit);
    $items = [];
    foreach (['relay', 'delivery', 'wakeup', 'process'] as $l) {
      if ($layer === null || $layer === $l) {
        array_push($items, ...$this->{$l}($limit));
      }
    }
    return array_slice($items, 0, $limit);
  }

  private function relay(int $limit): array {
    $outbox = $this->tables->table('ddd_outbox');
    $dlq = $this->tables->table('ddd_dlq');
    $items = [];
    foreach ($this->db->fetchAll("SELECT * FROM `$dlq` ORDER BY id LIMIT ?", [$limit]) as $r) {
      $items[] = $this->item('relay', (string) $r['event_id'], (int) $r['attempts'], (int) $r['max_attempts'], $r['error'], $r['dead_lettered_at'],
        ['retry', 'replay', 'discard'], ['state' => 'dead_lettered', 'dlq_id' => (int) $r['id'], 'event_type' => (string) $r['event_type']]);
    }
    foreach ($this->db->fetchAll(
      "SELECT * FROM `$outbox` WHERE status = 'pending' AND attempts > 0 ORDER BY next_attempt_at, id LIMIT ?",
      [$limit]
    ) as $r) {
      $items[] = $this->item('relay', (string) $r['event_id'], (int) $r['attempts'], (int) $r['max_attempts'], $r['last_error'], $r['created_at'],
        ['retry'], ['state' => 'retrying', 'event_type' => (string) $r['event_type'], 'next_attempt_at' => Utc::fromDb((string) $r['next_attempt_at'])]);
    }
    return $items;
  }

  private function delivery(int $limit): array {
    $ledger = $this->tables->table('ddd_delivery_ledger');
    $jobs = $this->tables->table('ddd_jobs');
    $items = [];
    foreach ($this->db->fetchAll(
      "SELECT * FROM `$ledger` WHERE delivered_at IS NULL AND (attempts > 0 OR exhausted_at IS NOT NULL)
       ORDER BY updated_at, subscriber_id, event_id LIMIT ?",
      [$limit]
    ) as $r) {
      $exhausted = $r['exhausted_at'] !== null;
      $items[] = $this->item('delivery', (string) $r['subscriber_id'], (int) $r['attempts'], $this->deliveryBudget, $r['last_error'], $r['updated_at'],
        $exhausted ? [] : ['redeliver'], ['state' => $exhausted ? 'exhausted' : 'retrying', 'subscriber_id' => (string) $r['subscriber_id'], 'event_id' => (string) $r['event_id']]);
    }
    foreach ($this->failedJobs($jobs, true, $limit) as $r) {
      $items[] = $this->item('delivery', (string) $r['idempotency_key'], (int) $r['attempts'], $this->deliveryBudget, $r['last_error'], $r['created_at'],
        [], ['state' => 'job_retrying', 'event_id' => (string) $r['event_id'], 'next_attempt_at' => Utc::fromDb((string) $r['next_attempt_at'])]);
    }
    return $items;
  }

  private function wakeup(int $limit): array {
    $items = [];
    foreach ($this->failedJobs($this->tables->table('ddd_jobs'), false, $limit) as $r) {
      $items[] = $this->item('wakeup', (string) $r['idempotency_key'], (int) $r['attempts'], $this->wakeBudget, $r['last_error'], $r['created_at'],
        [], ['state' => 'retrying', 'kind' => (string) $r['kind'], 'process_id' => $r['process_id'] === null ? null : (int) $r['process_id'],
             'next_attempt_at' => Utc::fromDb((string) $r['next_attempt_at'])]);
    }
    return $items;
  }

  private function process(int $limit): array {
    $table = $this->tables->table('ddd_processes');
    $items = [];
    $store = new PdoProcessStore($this->db, $this->tablePrefix, $this->clock, $this->strandedAfterSeconds);
    foreach (array_slice($store->findStranded($this->clock->now()), 0, $limit) as $s) {
      $items[] = [
        'layer' => 'process', 'consumer' => $this->consumer, 'key' => (string) $s->processId, 'attempts' => 0, 'budget' => 0,
        'last_error' => null, 'first_seen' => $s->updatedAt, 'repair_actions' => ['resume_stranded', 'fail_stranded'],
        'detail' => ['state' => 'stranded', 'status' => $s->status, 'process_class' => $s->processClass, 'step_index' => $s->stepIndex],
      ];
    }
    foreach ($this->db->fetchAll(
      "SELECT id, process_class, quarantine_reason, updated_at FROM `$table` WHERE quarantine_reason IS NOT NULL ORDER BY id LIMIT ?",
      [$limit]
    ) as $r) {
      $items[] = $this->item('process', (string) $r['id'], 0, 0, $r['quarantine_reason'], $r['updated_at'],
        [], ['state' => 'quarantined', 'process_class' => (string) $r['process_class']]);
    }
    return $items;
  }

  /** @return list<array<string, mixed>> */
  private function failedJobs(string $jobs, bool $deliver, int $limit): array {
    return $this->db->fetchAll(
      "SELECT * FROM `$jobs` WHERE attempts > 0 AND kind " . ($deliver ? '=' : '<>') . " 'deliver' ORDER BY next_attempt_at, id LIMIT ?",
      [$limit]
    );
  }

  /** @param list<string> $repairs @param array<string, mixed> $detail @return array<string, mixed> */
  private function item(string $layer, string $key, int $attempts, int $budget, mixed $error, mixed $firstSeen, array $repairs, array $detail): array {
    return [
      'layer' => $layer,
      'consumer' => $this->consumer,
      'key' => $key,
      'attempts' => $attempts,
      'budget' => $budget,
      'last_error' => $error === null ? null : (string) $error,
      'first_seen' => Utc::fromDb((string) $firstSeen),
      'repair_actions' => $repairs,
      'detail' => $detail,
    ];
  }
}
