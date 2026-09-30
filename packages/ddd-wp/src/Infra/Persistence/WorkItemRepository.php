<?php

namespace TangibleDDD\Infra\Persistence;

use DateTimeImmutable;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Infra\IDDDConfig;

/**
 * WPDB persistence for behaviour workflow work items.
 */
final class WorkItemRepository implements IWorkItemRepository {

  public function __construct(
    private readonly IDDDConfig $config
  ) {}

  public function get_by_id(int $id): WorkItem {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}` WHERE id = %d",
      $id
    ));

    if (!$row) {
      throw new \RuntimeException("WorkItem not found: {$id}");
    }

    return $this->item_from_row($row);
  }

  public function find_by_unique(
    int $workflow_id,
    int $behaviour_idx,
    int $phase,
    string $item_key
  ): ?WorkItem {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}`
       WHERE workflow_id = %d
         AND behaviour_idx = %d
         AND phase = %d
         AND item_key = %s",
      $workflow_id,
      $behaviour_idx,
      $phase,
      $item_key
    ));

    return $row ? $this->item_from_row($row) : null;
  }

  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList {
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}`
       WHERE workflow_id = %d
         AND behaviour_idx = %d
         AND phase = %d
       ORDER BY id ASC",
      $workflow_id,
      $behaviour_idx,
      $phase
    ));

    return new WorkItemList(array_map(fn($row) => $this->item_from_row($row), $rows ?: []));
  }

  public function save(WorkItem $item): void {
    global $wpdb;

    $now = gmdate('Y-m-d H:i:s');

    $row = [
      'workflow_id' => $item->workflow_id,
      'behaviour_idx' => $item->behaviour_idx,
      'phase' => $item->phase,
      'item_key' => $item->item_key,
      'status' => $item->status->value,
      'attempts' => $item->attempts,
      'last_error' => $item->last_error,
      'payload' => $item->payload === null
        ? null
        : wp_json_encode($item->payload, JSON_UNESCAPED_SLASHES),
      'updated_at' => $now,
      'blog_id' => is_multisite() ? get_current_blog_id() : 1,
    ];

    // If no ID, try to locate by natural unique key first (idempotent insert/update).
    if ($item->get_id() === null) {
      $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM `{$this->table_name()}`
         WHERE workflow_id = %d
           AND behaviour_idx = %d
           AND phase = %d
           AND item_key = %s",
        $item->workflow_id,
        $item->behaviour_idx,
        $item->phase,
        $item->item_key
      ));

      if ($existing_id > 0) {
        $item->set_id($existing_id);
      }
    }

    if ($item->get_id() === null) {
      $row['created_at'] = $now;
      $wpdb->insert($this->table_name(), $row);
      $item->set_id((int) $wpdb->insert_id);
      return;
    }

    $wpdb->update(
      $this->table_name(),
      $row,
      ['id' => $item->get_id()]
    );
  }

  private function item_from_row(object $row): WorkItem {
    $payload = null;
    if (!empty($row->payload)) {
      $decoded = json_decode($row->payload, true);
      $payload = $decoded === null ? null : $decoded;
    }

    $item = new WorkItem(
      id: (int) $row->id,
      workflow_id: (int) $row->workflow_id,
      behaviour_idx: (int) $row->behaviour_idx,
      phase: (int) $row->phase,
      item_key: (string) $row->item_key,
      status: WorkItemStatus::from((string) $row->status),
      attempts: (int) $row->attempts,
      last_error: $row->last_error !== null ? (string) $row->last_error : null,
      payload: $payload,
      blog_id: (int) $row->blog_id
    );

    $item->created_at = !empty($row->created_at) ? new DateTimeImmutable((string) $row->created_at) : null;
    $item->updated_at = !empty($row->updated_at) ? new DateTimeImmutable((string) $row->updated_at) : null;

    return $item;
  }

  private function table_name(): string {
    return $this->config->table('behaviour_workflow_items');
  }
}


