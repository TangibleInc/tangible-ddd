<?php

namespace TangibleDDD\Infra\Persistence;

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

    return $this->workflow_from_row($row);
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

    return array_map(fn($row) => $this->workflow_from_row($row), $rows ?: []);
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

    $rows = $wpdb->get_results($sql);
    $workflows = [];

    foreach ($rows as $row) {
      $workflow = $this->workflow_from_row($row);
      $request_id = $workflow->get_ref_id();
      $attempt_id = (int) ($workflow->get_all_meta()['attempt_id'] ?? 0);
      $workflows[$request_id][$attempt_id][] = $workflow;
    }

    return $workflows;
  }

  public function save(BehaviourWorkflow $workflow): void {
    parent::save($workflow);
  }

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
      'meta' => wp_json_encode($aggregate->get_all_meta(), JSON_UNESCAPED_SLASHES),
      'updated_at' => $now,
      'blog_id' => is_multisite() ? get_current_blog_id() : 1,
    ];

    if ($aggregate->get_id() === null) {
      $row['created_at'] = $now;
      $wpdb->insert($this->table_name(), $row);
      $aggregate->set_id((int) $wpdb->insert_id);
      return;
    }

    $wpdb->update(
      $this->table_name(),
      $row,
      ['id' => $aggregate->get_id()]
    );
  }

  private function workflow_from_row(object $row): BehaviourWorkflow {
    $configs_json = json_decode($row->behaviour_configs);
    $results_json = json_decode($row->behaviour_results);

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
      meta: $row->meta ? (json_decode($row->meta, true) ?: []) : [],
      root_workflow_id: $row->root_workflow_id ? (int) $row->root_workflow_id : null,
    );
  }

  private function table_name(): string {
    return $this->config->table('behaviour_workflows');
  }
}


