<?php

namespace TangibleDDD\Infra\Persistence;

use DateTimeImmutable;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;

/**
 * MySQL/WordPress implementation of the process repository.
 */
class ProcessRepository implements IProcessRepository {

  public function __construct(
    private readonly IDDDConfig $config
  ) {}

  public function save(LongProcess $process): int {
    global $wpdb;

    $now = gmdate('Y-m-d H:i:s');
    $process_data = serialize($process);

    $row = [
      'process_class' => get_class($process),
      'process_data' => $process_data,
      'current_step' => $process->current_step(),
      'status' => $process->status(),
      'waiting_for' => $process->waiting_for(),
      'match_criteria' => $process->match_criteria() ? wp_json_encode($process->match_criteria()) : null,
      'payload' => $process->payload() !== null ? serialize($process->payload()) : null,
      'correlation_id' => $process->correlation_id(),
      'last_error' => $process->last_error(),
      'updated_at' => $now,
      'blog_id' => is_multisite() ? get_current_blog_id() : 1,
    ];

    if ($process->get_id() === null) {
      // Insert new process
      $row['created_at'] = $now;
      $wpdb->insert($this->table_name(), $row);
      $id = (int) $wpdb->insert_id;
      $process->set_id($id);
      return $id;
    }

    // Update existing process
    $wpdb->update(
      $this->table_name(),
      $row,
      ['id' => $process->get_id()]
    );

    return $process->get_id();
  }

  public function find(int $id): ?LongProcess {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}` WHERE id = %d",
      $id
    ));

    if (!$row) {
      return null;
    }

    return $this->process_from_row($row);
  }

  public function find_waiting_for(string $event_class): array {
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}`
       WHERE waiting_for = %s
         AND status = 'suspended'",
      $event_class
    ));

    return array_map(fn($row) => $this->process_from_row($row), $rows);
  }

  public function delete(int $id): void {
    global $wpdb;

    $wpdb->delete($this->table_name(), ['id' => $id]);
  }

  private function table_name(): string {
    return $this->config->table('long_processes');
  }

  private function process_from_row(object $row): LongProcess {
    /** @var LongProcess $process */
    $process = unserialize($row->process_data);

    // Hydrate framework state
    $process->hydrate_state(
      id: (int) $row->id,
      current_step: (int) $row->current_step,
      status: $row->status,
      correlation_id: $row->correlation_id,
      waiting_for: $row->waiting_for,
      match_criteria: $row->match_criteria ? json_decode($row->match_criteria, true) : null,
      payload: $row->payload ? unserialize($row->payload) : null,
      last_error: $row->last_error,
      created_at: $row->created_at ? new DateTimeImmutable($row->created_at) : null,
      updated_at: $row->updated_at ? new DateTimeImmutable($row->updated_at) : null,
    );

    return $process;
  }
}
