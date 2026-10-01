<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;

/**
 * IWorkItemRepository on Postgres 16 (D10, ruling #78): the WordPress
 * WorkItemRepository's semantics on `ddd_behaviour_workflow_items`.
 *
 * save() of an item without an id is an idempotent upsert on the natural
 * key (workflow_id, behaviour_idx, phase, item_key): one
 * `INSERT ... ON CONFLICT ... DO UPDATE ... RETURNING id`, so two workers
 * saving the same new item end with one row and both items hydrated with
 * its id (WordPress does a SELECT then INSERT). An item with an id is
 * updated by id. get_by_id() of an unknown id throws \RuntimeException.
 */
final class DbalWorkItemRepository implements IWorkItemRepository {

  private readonly string $table;

  public function __construct(private readonly Connection $connection, string $tablePrefix = '') {
    $this->table = TableNames::of($tablePrefix)->table('ddd_behaviour_workflow_items');
  }

  public function get_by_id(int $id): WorkItem {
    $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$id], [ParameterType::INTEGER]);
    if ($row === false) {
      throw new \RuntimeException("WorkItem not found: {$id}");
    }
    return self::fromRow($row);
  }

  public function find_by_unique(int $workflow_id, int $behaviour_idx, int $phase, string $item_key): ?WorkItem {
    $row = $this->connection->fetchAssociative(
      "SELECT * FROM {$this->table} WHERE workflow_id = ? AND behaviour_idx = ? AND phase = ? AND item_key = ?",
      [$workflow_id, $behaviour_idx, $phase, $item_key],
      [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING]
    );
    return $row === false ? null : self::fromRow($row);
  }

  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList {
    $rows = $this->connection->fetchAllAssociative(
      "SELECT * FROM {$this->table} WHERE workflow_id = ? AND behaviour_idx = ? AND phase = ? ORDER BY id",
      [$workflow_id, $behaviour_idx, $phase],
      [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER]
    );
    return new WorkItemList(array_map(static fn (array $r) => self::fromRow($r), $rows));
  }

  public function save(WorkItem $item): void {
    $now = Time::to_db(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $row = [
      'workflow_id' => $item->workflow_id,
      'behaviour_idx' => $item->behaviour_idx,
      'phase' => $item->phase,
      'item_key' => $item->item_key,
      'status' => $item->status->value,
      'attempts' => $item->attempts,
      'last_error' => $item->last_error,
      'payload' => $item->payload === null ? null : json_encode($item->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      'blog_id' => $item->blog_id,
      'updated_at' => $now,
    ];
    $types = [
      'workflow_id' => ParameterType::INTEGER, 'behaviour_idx' => ParameterType::INTEGER, 'phase' => ParameterType::INTEGER,
      'attempts' => ParameterType::INTEGER, 'blog_id' => ParameterType::INTEGER,
    ];

    if ($item->get_id() !== null) {
      $this->connection->update($this->table, $row, ['id' => $item->get_id()], $types + ['id' => ParameterType::INTEGER]);
      return;
    }

    $id = $this->connection->fetchOne(
      "INSERT INTO {$this->table} (workflow_id, behaviour_idx, phase, item_key, status, attempts, last_error, payload, blog_id, created_at, updated_at)
       VALUES (:workflow_id, :behaviour_idx, :phase, :item_key, :status, :attempts, :last_error, :payload, :blog_id, :updated_at, :updated_at)
       ON CONFLICT (workflow_id, behaviour_idx, phase, item_key) DO UPDATE SET
         status = EXCLUDED.status, attempts = EXCLUDED.attempts, last_error = EXCLUDED.last_error,
         payload = EXCLUDED.payload, blog_id = EXCLUDED.blog_id, updated_at = EXCLUDED.updated_at
       RETURNING id",
      $row,
      $types
    );
    $item->set_id((int) $id);
  }

  /** @param array<string, mixed> $row */
  private static function fromRow(array $row): WorkItem {
    $payload = null;
    if ($row['payload'] !== null && $row['payload'] !== '') {
      $payload = json_decode((string) $row['payload'], true);
    }
    $item = new WorkItem(
      id: (int) $row['id'],
      workflow_id: (int) $row['workflow_id'],
      behaviour_idx: (int) $row['behaviour_idx'],
      phase: (int) $row['phase'],
      item_key: (string) $row['item_key'],
      status: WorkItemStatus::from((string) $row['status']),
      attempts: (int) $row['attempts'],
      last_error: $row['last_error'] === null ? null : (string) $row['last_error'],
      payload: $payload,
      blog_id: (int) $row['blog_id'],
    );
    $item->created_at = Time::from_db_or_null($row['created_at'] === null ? null : (string) $row['created_at']);
    $item->updated_at = Time::from_db_or_null($row['updated_at'] === null ? null : (string) $row['updated_at']);
    return $item;
  }
}
