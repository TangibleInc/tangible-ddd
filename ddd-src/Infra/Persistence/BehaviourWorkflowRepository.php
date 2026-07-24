<?php

namespace TangibleDDD\Infra\Persistence;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository;

/**
 * WPDB implementation for behaviour workflows.
 *
 * Storage is "raw" like other infra tables: a single table with JSON columns.
 */
final class BehaviourWorkflowRepository extends PersistsAggregatesRepository implements IBehaviourWorkflowRepository {

  public function __construct(
    EventsUnitOfWork $events,
    private readonly IDDDConfig $config
  ) {
    parent::__construct($events);
  }

  protected function get_aggregate_class(): string {
    return BehaviourWorkflow::class;
  }

  public function get_by_id(int $id): BehaviourWorkflow {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}` WHERE id = %d",
      $id
    ));

    if (!$row) {
      throw new \RuntimeException("BehaviourWorkflow not found: {$id}");
    }

    return $this->workflow_from_row($row, $this->meta_for([$id])[$id] ?? null);
  }

  public function get_by_ref_id(int $ref_id, string $ref_type): array {
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}`
       WHERE ref_id = %d AND ref_type = %s
       ORDER BY id ASC",
      $ref_id,
      $ref_type
    ));

    $rows = $rows ?: [];
    $meta = $this->meta_for(array_map(static fn($row) => (int) $row->id, $rows));

    return array_map(fn($row) => $this->workflow_from_row($row, $meta[(int) $row->id] ?? null), $rows);
  }

  public function get_for_requests(array $request_ids): array {
    global $wpdb;

    $request_ids = array_values(array_filter(array_map('intval', $request_ids)));
    if (empty($request_ids)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($request_ids), '%d'));
    $sql = $wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}`
       WHERE ref_type = %s AND ref_id IN ($placeholders)
       ORDER BY ref_id ASC, id ASC",
      array_merge(['request'], $request_ids)
    );

    $rows = $wpdb->get_results($sql) ?: [];
    $meta = $this->meta_for(array_map(static fn($row) => (int) $row->id, $rows));
    $workflows = [];

    foreach ($rows as $row) {
      $workflow = $this->workflow_from_row($row, $meta[(int) $row->id] ?? null);
      $request_id = $workflow->get_ref_id();
      $attempt_id = (int) ($workflow->get_all_meta()['attempt_id'] ?? 0);
      $workflows[$request_id][$attempt_id][] = $workflow;
    }

    return $workflows;
  }

  // save() is inherited from PersistsAggregatesRepository::save(Aggregate).
  // PHP does not allow narrowing the parameter type in a child class, so we
  // omit the override and rely on the parent's runtime type-check via
  // get_aggregate_class() / TypeMismatchException.

  protected function persist(Aggregate $aggregate): void {
    /** @var BehaviourWorkflow $aggregate */
    global $wpdb;

    $now = gmdate('Y-m-d H:i:s');

    $row = [
      'ref_id' => $aggregate->get_ref_id(),
      'ref_type' => $aggregate->get_ref_type(),
      'root_workflow_id' => $aggregate->get_root_workflow_id(),
      'behaviour_configs' => BaseBehaviourConfig::array_to_json($aggregate->get_behaviour_configs(), true),
      'behaviour_results' => BehaviourExecutionResult::array_to_json($aggregate->get_behaviour_results(), true),
      'current_idx' => $aggregate->get_current_idx(),
      'current_phase' => $aggregate->get_current_phase(),
      'is_complete' => $aggregate->is_complete() ? 1 : 0,
      'is_failed' => $aggregate->is_failed() ? 1 : 0,
      'correlation_id' => Correlation::peek()?->correlation_id,
      // v7: meta lives in the side table; the JSON column is write-dead and
      // nulled on update so a row never carries two divergent meta stories.
      'meta' => null,
      'updated_at' => $now,
      'blog_id' => is_multisite() ? get_current_blog_id() : 1,
    ];

    if ($aggregate->get_id() === null) {
      $row['created_at'] = $now;
      $wpdb->insert($this->table_name(), $row);
      $aggregate->set_id((int) $wpdb->insert_id);
    } else {
      $wpdb->update(
        $this->table_name(),
        $row,
        ['id' => $aggregate->get_id()]
      );
    }

    $this->persist_meta((int) $aggregate->get_id(), $aggregate->get_all_meta());
  }

  /**
   * Rewrite the workflow's meta rows (WP-meta idiom, one row per key).
   * Values are stringly-typed at rest; non-scalars stored as JSON text.
   * Identity (correlation) is a stamped column on the workflow row and is
   * never duplicated into meta.
   */
  private function persist_meta(int $workflow_id, array $meta): void {
    global $wpdb;

    $wpdb->delete($this->meta_table_name(), ['id' => $workflow_id]);

    foreach ($meta as $key => $value) {
      $wpdb->insert($this->meta_table_name(), [
        'id' => $workflow_id,
        'meta_key' => (string) $key,
        'meta_value' => is_scalar($value) || $value === null
          ? (string) $value
          : wp_json_encode($value, JSON_UNESCAPED_SLASHES),
      ]);
    }
  }

  /**
   * Load meta for a set of workflow rows in one query.
   * Values starting with { or [ decode as JSON (the non-scalar lane);
   * everything else stays a string — consumers cast on read.
   *
   * @param int[] $workflow_ids
   * @return array<int, array<string, mixed>>
   */
  private function meta_for(array $workflow_ids): array {
    global $wpdb;

    $workflow_ids = array_values(array_filter(array_map('intval', $workflow_ids)));
    if (empty($workflow_ids)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($workflow_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, meta_key, meta_value FROM `{$this->meta_table_name()}`
       WHERE id IN ($placeholders) ORDER BY meta_id ASC",
      $workflow_ids
    ));

    $meta = [];
    foreach ($rows ?: [] as $row) {
      $value = $row->meta_value;
      if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
        $decoded = json_decode($value, true);
        if ($decoded !== null) {
          $value = $decoded;
        }
      }
      $meta[(int) $row->id][(string) $row->meta_key] = $value;
    }

    return $meta;
  }

  /**
   * @param array<string, mixed>|null $meta Side-table meta for this row; null
   *   means "no rows found" — an unbackfilled pre-v7 row, so fall back to the
   *   legacy JSON column. (Post-v7 saves always have rows or genuinely-empty meta.)
   */
  private function workflow_from_row(object $row, ?array $meta = null): BehaviourWorkflow {
    $configs_json = json_decode($row->behaviour_configs);
    $results_json = json_decode($row->behaviour_results);

    if ($meta === null) {
      $meta = $row->meta ? (json_decode($row->meta, true) ?: []) : [];
    }

    return new BehaviourWorkflow(
      id: (int) $row->id,
      ref_id: (int) $row->ref_id,
      ref_type: (string) $row->ref_type,
      behaviour_configs: BaseBehaviourConfig::array_from_json(is_array($configs_json) ? $configs_json : [], false),
      behaviour_results: BehaviourExecutionResult::array_from_json(is_array($results_json) ? $results_json : [], false),
      current_idx: (int) $row->current_idx,
      current_phase: (int) $row->current_phase,
      is_complete: (bool) $row->is_complete,
      is_failed: (bool) $row->is_failed,
      meta: $meta,
      root_workflow_id: $row->root_workflow_id ? (int) $row->root_workflow_id : null,
    );
  }

  private function table_name(): string {
    return $this->config->table('behaviour_workflows');
  }

  private function meta_table_name(): string {
    return $this->config->table('behaviour_workflows_meta');
  }
}


