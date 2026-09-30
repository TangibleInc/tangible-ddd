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
   * Has this process class already been ignited by this integration event?
   * The #[StartsOn] replay-dedup check — event_id is per-publication stable
   * across retries and replays, so redelivery can never mint a second saga.
   */
  public function has_ignition(string $process_class, string $event_id): bool;

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
