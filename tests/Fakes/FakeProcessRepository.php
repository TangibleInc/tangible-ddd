<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Infra\IProcessRepository;

final class FakeProcessRepository implements IProcessRepository {

  /** @var array<int, LongProcess> */
  public array $processes = [];

  private int $next_id = 1;

  /** @var int */
  public int $save_count = 0;

  public function save(LongProcess $process): int {
    $this->save_count++;

    if ($process->get_id() === null) {
      $process->set_id($this->next_id++);
    }

    $this->processes[$process->get_id()] = $process;

    return $process->get_id();
  }

  public function find(int $id): ?LongProcess {
    return $this->processes[$id] ?? null;
  }

  public function has_ignition(string $process_class, string $event_id): bool {
    foreach ($this->processes as $p) {
      if ($p instanceof $process_class && $p->ignited_by_event_id() === $event_id) {
        return true;
      }
    }
    return false;
  }

  public function find_waiting_for(string $event_class): array {
    return array_filter(
      $this->processes,
      fn(LongProcess $p) => $p->waiting_for() === $event_class
    );
  }

  public function delete(int $id): void {
    unset($this->processes[$id]);
  }
}
