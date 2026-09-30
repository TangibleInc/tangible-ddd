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

  /**
   * Race seam: called with the answer after has_ignition() computed it and
   * before it returns, i.e. in the window between the check and the insert.
   * A test uses it to let a "second worker" act inside that window.
   *
   * @var null|\Closure(string $process_class, string $event_id, bool $answer): void
   */
  public ?\Closure $after_has_ignition = null;

  /** @var list<array{0: string, 1: string}> every has_ignition() call */
  public array $ignition_checks = [];

  public function has_ignition(string $process_class, string $event_id): bool {
    $this->ignition_checks[] = [$process_class, $event_id];
    $answer = false;
    foreach ($this->processes as $p) {
      if ($p instanceof $process_class && $p->ignited_by_event_id() === $event_id) {
        $answer = true;
        break;
      }
    }
    if ($this->after_has_ignition !== null) {
      ($this->after_has_ignition)($process_class, $event_id, $answer);
    }
    return $answer;
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
