<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IBehaviourWorkflowRepository on MySQL 8 (D10, O8; wave 4): the WordPress
 * BehaviourWorkflowRepository's semantics on `{prefix}ddd_behaviour_workflows`
 * plus the `{prefix}ddd_behaviour_workflow_meta` side table (one row per key;
 * non-scalars as JSON text, read back by a leading `{` or `[`), the same as
 * ddd-symfony's DbalBehaviourWorkflowRepository.
 *
 * save() is the core PersistsAggregatesRepository::save(): persist, then
 * harvest the aggregate's domain events into the unit of work. A save writes
 * the row and rewrites its meta in one transaction (the caller's when one is
 * open, else its own). The correlation id is stamped from the ambient
 * Correlation scope, as on WordPress. get_by_id() of an unknown id throws
 * \RuntimeException (WordPress parity). Times are UTC from IClock.
 */
final class PdoBehaviourWorkflowRepository extends PersistsAggregatesRepository implements IBehaviourWorkflowRepository {

  private readonly string $table;
  private readonly string $meta;
  private readonly IClock $clock;

  public function __construct(
    EventsUnitOfWork $events,
    private readonly IHostConnection $db,
    string $tablePrefix = '',
    ?IClock $clock = null,
  ) {
    parent::__construct($events);
    $tables = new PrefixedTableNames($tablePrefix);
    $this->table = $tables->table('ddd_behaviour_workflows');
    $this->meta = $tables->table('ddd_behaviour_workflow_meta');
    $this->clock = $clock ?? new SystemClock();
  }

  protected function get_aggregate_class(): string {
    return BehaviourWorkflow::class;
  }

  public function get_by_id(int $id): BehaviourWorkflow {
    $row = $this->db->fetch_one("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
    if ($row === null) {
      throw new \RuntimeException("BehaviourWorkflow not found: {$id}");
    }
    return $this->fromRow($row, $this->metaFor([$id])[$id] ?? []);
  }

  public function get_by_ref_id(int $ref_id, string $ref_type): array {
    $rows = $this->db->fetch_all("SELECT * FROM `{$this->table}` WHERE ref_id = ? AND ref_type = ? ORDER BY id", [$ref_id, $ref_type]);
    $meta = $this->metaFor(array_map(static fn (array $r) => (int) $r['id'], $rows));
    return array_map(fn (array $r) => $this->fromRow($r, $meta[(int) $r['id']] ?? []), $rows);
  }

  protected function persist(Aggregate $aggregate): void {
    /** @var BehaviourWorkflow $aggregate */
    $now = Utc::to_db($this->clock->now());
    $row = [
      'ref_id' => $aggregate->get_ref_id(),
      'ref_type' => $aggregate->get_ref_type(),
      'root_workflow_id' => $aggregate->get_root_workflow_id(),
      'behaviour_configs' => BaseBehaviourConfig::array_to_json($aggregate->get_behaviour_configs(), true),
      'behaviour_results' => BehaviourExecutionResult::array_to_json($aggregate->get_behaviour_results(), true),
      'current_idx' => $aggregate->get_current_idx(),
      'current_phase' => $aggregate->get_current_phase(),
      'is_complete' => $aggregate->is_complete(),
      'is_failed' => $aggregate->is_failed(),
      'correlation_id' => Correlation::peek()?->correlation_id,
      'updated_at' => $now,
    ];

    $this->atomically(function () use ($aggregate, $row, $now): void {
      if ($aggregate->get_id() === null) {
        $row['created_at'] = $now;
        $names = implode(', ', array_map(static fn (string $c) => "`$c`", array_keys($row)));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $this->db->execute("INSERT INTO `{$this->table}` ($names) VALUES ($marks)", array_values($row));
        $id = (int) $this->db->last_insert_id();
        if ($id <= 0) {
          throw new \RuntimeException('Inserting a BehaviourWorkflow returned no id');
        }
        $aggregate->set_id($id);
      } else {
        $set = implode(', ', array_map(static fn (string $c) => "`$c` = ?", array_keys($row)));
        $this->db->execute("UPDATE `{$this->table}` SET $set WHERE id = ?", [...array_values($row), (int) $aggregate->get_id()]);
      }
      $this->writeMeta((int) $aggregate->get_id(), $aggregate->get_all_meta());
    });
  }

  /** @param array<string, mixed> $meta */
  private function writeMeta(int $id, array $meta): void {
    $this->db->execute("DELETE FROM `{$this->meta}` WHERE workflow_id = ?", [$id]);
    foreach ($meta as $key => $value) {
      $this->db->execute(
        "INSERT INTO `{$this->meta}` (workflow_id, meta_key, meta_value) VALUES (?, ?, ?)",
        [$id, (string) $key, match (true) {
          $value === null => null,
          is_bool($value) => $value ? '1' : '',
          is_scalar($value) => (string) $value,
          default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        }]
      );
    }
  }

  /**
   * @param list<int> $ids
   * @return array<int, array<string, mixed>>
   */
  private function metaFor(array $ids): array {
    if ($ids === []) {
      return [];
    }
    $in = implode(', ', array_fill(0, count($ids), '?'));
    $meta = [];
    foreach ($this->db->fetch_all("SELECT workflow_id, meta_key, meta_value FROM `{$this->meta}` WHERE workflow_id IN ($in) ORDER BY meta_id", $ids) as $r) {
      $value = $r['meta_value'];
      if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
        $decoded = json_decode($value, true);
        if ($decoded !== null) {
          $value = $decoded;
        }
      }
      $meta[(int) $r['workflow_id']][(string) $r['meta_key']] = $value;
    }
    return $meta;
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $meta
   */
  private function fromRow(array $row, array $meta): BehaviourWorkflow {
    $configs = json_decode((string) $row['behaviour_configs']);
    $results = json_decode((string) $row['behaviour_results']);

    return new BehaviourWorkflow(
      id: (int) $row['id'],
      ref_id: (int) $row['ref_id'],
      ref_type: (string) $row['ref_type'],
      behaviour_configs: BaseBehaviourConfig::array_from_json(is_array($configs) ? $configs : [], false),
      behaviour_results: BehaviourExecutionResult::array_from_json(is_array($results) ? $results : [], false),
      current_idx: (int) $row['current_idx'],
      current_phase: (int) $row['current_phase'],
      is_complete: (bool) $row['is_complete'],
      is_failed: (bool) $row['is_failed'],
      meta: $meta,
      root_workflow_id: $row['root_workflow_id'] === null ? null : (int) $row['root_workflow_id'],
    );
  }

  private function atomically(callable $work): void {
    if ($this->db->in_transaction()) {
      $work();
      return;
    }
    $this->db->begin();
    try {
      $work();
      $this->db->commit();
    } catch (\Throwable $e) {
      if ($this->db->in_transaction()) {
        $this->db->rollback();
      }
      throw $e;
    }
  }
}
