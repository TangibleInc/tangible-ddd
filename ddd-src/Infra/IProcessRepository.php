<?php

namespace TangibleDDD\Infra;

use TangibleDDD\Application\Process\LongProcess;

/**
 * Repository interface for long-running processes.
 */
interface IProcessRepository {
  /**
   * Save a process (insert or update).
   *
   * @return int The process ID
   */
  public function save(LongProcess $process): int;

  /**
   * Find a process by ID.
   */
  public function find(int $id): ?LongProcess;

  /**
   * Find processes waiting for a specific event type.
   *
   * @return LongProcess[]
   */
  public function find_waiting_for(string $event_class): array;

  /**
   * Delete a process.
   */
  public function delete(int $id): void;
}
