<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository;

/**
 * IBehaviourWorkflowRepository on Postgres 16 (D10, ruling #78): the
 * WordPress BehaviourWorkflowRepository's semantics on `ddd_behaviour_workflows`
 * plus the `ddd_behaviour_workflow_meta` side table (one row per key;
 * non-scalars as JSON text, read back by a leading `{` or `[`).
 *
 * save() is the core PersistsAggregatesRepository::save(): persist, then
 * harvest the aggregate's domain events into the unit of work. A save
 * writes the row and rewrites its meta in one transaction (the caller's when
 * one is open). The workflow's correlation id is stamped from the ambient
 * Correlation scope, as on WordPress. get_by_id() of an unknown id throws
 * \RuntimeException (WordPress parity).
 */
final class DbalBehaviourWorkflowRepository extends PersistsAggregatesRepository implements IBehaviourWorkflowRepository {

  private readonly string $table;
  private readonly string $meta;

  public function __construct(
    EventsUnitOfWork $events,
    private readonly Connection $connection,
    string $tablePrefix = '',
  ) {
    parent::__construct($events);
    $tables = TableNames::of($tablePrefix);
    $this->table = $tables->table('ddd_behaviour_workflows');
    $this->meta = $tables->table('ddd_behaviour_workflow_meta');
  }

  protected function get_aggregate_class(): string {
    return BehaviourWorkflow::class;
  }

  public function get_by_id(int $id): BehaviourWorkflow {
    $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$id], [ParameterType::INTEGER]);
    if ($row === false) {
      throw new \RuntimeException("BehaviourWorkflow not found: {$id}");
    }
    return $this->fromRow($row, $this->metaFor([$id])[$id] ?? []);
  }

  public function get_by_ref_id(int $ref_id, string $ref_type): array {
    $rows = $this->connection->fetchAllAssociative(
      "SELECT * FROM {$this->table} WHERE ref_id = ? AND ref_type = ? ORDER BY id",
      [$ref_id, $ref_type],
      [ParameterType::INTEGER, ParameterType::STRING]
    );
    $meta = $this->metaFor(array_map(static fn (array $r) => (int) $r['id'], $rows));
    return array_map(fn (array $r) => $this->fromRow($r, $meta[(int) $r['id']] ?? []), $rows);
  }

  protected function persist(Aggregate $aggregate): void {
    /** @var BehaviourWorkflow $aggregate */
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
      'updated_at' => Time::to_db(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
    ];
    $types = [
      'ref_id' => ParameterType::INTEGER, 'root_workflow_id' => ParameterType::INTEGER,
      'current_idx' => ParameterType::INTEGER, 'current_phase' => ParameterType::INTEGER,
      'is_complete' => ParameterType::BOOLEAN, 'is_failed' => ParameterType::BOOLEAN,
    ];

    $write = function () use ($aggregate, $row, $types): void {
      if ($aggregate->get_id() === null) {
        $names = implode(', ', array_keys($row));
        $values = implode(', ', array_map(static fn (string $c) => ":$c", array_keys($row)));
        $id = $this->connection->fetchOne("INSERT INTO {$this->table} ($names) VALUES ($values) RETURNING id", $row, $types);
        $aggregate->set_id((int) $id);
      } else {
        $this->connection->update($this->table, $row, ['id' => $aggregate->get_id()], $types + ['id' => ParameterType::INTEGER]);
      }
      $this->writeMeta((int) $aggregate->get_id(), $aggregate->get_all_meta());
    };

    $this->connection->isTransactionActive() ? $write() : $this->connection->transactional($write);
  }

  /** @param array<string, mixed> $meta */
  private function writeMeta(int $id, array $meta): void {
    $this->connection->executeStatement("DELETE FROM {$this->meta} WHERE workflow_id = ?", [$id], [ParameterType::INTEGER]);
    foreach ($meta as $key => $value) {
      $this->connection->insert($this->meta, [
        'workflow_id' => $id,
        'meta_key' => (string) $key,
        'meta_value' => is_scalar($value) || $value === null
          ? ($value === null ? null : (string) $value)
          : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      ], ['workflow_id' => ParameterType::INTEGER]);
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
    $rows = $this->connection->fetchAllAssociative(
      "SELECT workflow_id, meta_key, meta_value FROM {$this->meta} WHERE workflow_id IN (?) ORDER BY meta_id",
      [$ids],
      [ArrayParameterType::INTEGER]
    );
    $meta = [];
    foreach ($rows as $r) {
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
}
