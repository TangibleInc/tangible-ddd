<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IWorkItemRepository on MySQL 8 (D10, O8; wave 4): the WordPress
 * WorkItemRepository's semantics on `{prefix}ddd_behaviour_workflow_items`.
 *
 * save() of an item without an id is an idempotent upsert on the natural key
 * (workflow_id, behaviour_idx, phase, item_key): one `INSERT ... ON DUPLICATE
 * KEY UPDATE ..., id = LAST_INSERT_ID(id)`, so two workers saving the same
 * new item end with one row and both items hydrated with its id (WordPress
 * does a SELECT then INSERT; ddd-symfony an ON CONFLICT upsert). An item
 * with an id is updated by id. get_by_id() of an unknown id throws
 * \RuntimeException. Writes run on the host connection (the caller's
 * transaction when one is open). Times are UTC from IClock.
 */
final class PdoWorkItemRepository implements IWorkItemRepository {

  private readonly string $table;
  private readonly IClock $clock;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix = '', ?IClock $clock = null) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_behaviour_workflow_items');
    $this->clock = $clock ?? new SystemClock();
  }

  public function get_by_id(int $id): WorkItem {
    $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
    if ($row === null) {
      throw new \RuntimeException("WorkItem not found: {$id}");
    }
    return self::fromRow($row);
  }

  public function find_by_unique(int $workflow_id, int $behaviour_idx, int $phase, string $item_key): ?WorkItem {
    $row = $this->db->fetchOne(
      "SELECT * FROM `{$this->table}` WHERE workflow_id = ? AND behaviour_idx = ? AND phase = ? AND item_key = ?",
      [$workflow_id, $behaviour_idx, $phase, $item_key]
    );
    return $row === null ? null : self::fromRow($row);
  }

  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList {
    $rows = $this->db->fetchAll(
      "SELECT * FROM `{$this->table}` WHERE workflow_id = ? AND behaviour_idx = ? AND phase = ? ORDER BY id",
      [$workflow_id, $behaviour_idx, $phase]
    );
    return new WorkItemList(array_map(static fn (array $r) => self::fromRow($r), $rows));
  }

  public function save(WorkItem $item): void {
    $now = Utc::toDb($this->clock->now());
    $payload = $item->payload === null ? null : json_encode($item->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if ($item->get_id() !== null) {
      $this->db->execute(
        "UPDATE `{$this->table}` SET workflow_id = ?, behaviour_idx = ?, phase = ?, item_key = ?, status = ?, attempts = ?,
           last_error = ?, payload = ?, blog_id = ?, updated_at = ? WHERE id = ?",
        [$item->workflow_id, $item->behaviour_idx, $item->phase, $item->item_key, $item->status->value, $item->attempts,
         $item->last_error, $payload, $item->blog_id, $now, (int) $item->get_id()]
      );
      return;
    }

    $this->db->execute(
      "INSERT INTO `{$this->table}`
         (workflow_id, behaviour_idx, phase, item_key, status, attempts, last_error, payload, blog_id, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         id = LAST_INSERT_ID(id), status = VALUES(status), attempts = VALUES(attempts), last_error = VALUES(last_error),
         payload = VALUES(payload), blog_id = VALUES(blog_id), updated_at = VALUES(updated_at)",
      [$item->workflow_id, $item->behaviour_idx, $item->phase, $item->item_key, $item->status->value, $item->attempts,
       $item->last_error, $payload, $item->blog_id, $now, $now]
    );
    $id = (int) $this->db->lastInsertId();
    if ($id <= 0) {
      throw new \RuntimeException("Saving work item {$item->item_key} returned no id");
    }
    $item->set_id($id);
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
    $item->created_at = Utc::fromDbOrNull($row['created_at']);
    $item->updated_at = Utc::fromDbOrNull($row['updated_at']);
    return $item;
  }
}
