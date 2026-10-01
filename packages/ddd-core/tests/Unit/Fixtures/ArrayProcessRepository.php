<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Infra\IProcessRepository;

/**
 * A consumer-authored 0.6 IProcessRepository (cred-style), array-backed.
 * Keeps serialized copies, so find() never hands back the live instance.
 */
final class ArrayProcessRepository implements IProcessRepository {

  /** @var array<int, string> */
  public array $rows = [];

  public int $saves = 0;

  /** Make the next save() return 0 without assigning an id (a broken repository). */
  public bool $breakNextSave = false;

  private int $next = 1;

  public function save(LongProcess $process): int {
    if ($this->breakNextSave) {
      $this->breakNextSave = false;
      return 0;
    }
    $this->saves++;
    if ($process->get_id() === null) {
      $process->set_id($this->next++);
    }
    $this->rows[$process->get_id()] = serialize($process);
    return $process->get_id();
  }

  public function find(int $id): ?LongProcess {
    return isset($this->rows[$id]) ? unserialize($this->rows[$id]) : null;
  }

  public function has_ignition(string $process_class, string $event_id): bool {
    foreach ($this->rows as $blob) {
      $p = unserialize($blob);
      if ($p instanceof $process_class && $p->ignited_by_event_id() === $event_id) {
        return true;
      }
    }
    return false;
  }

  public function find_waiting_for(string $event_class): array {
    $out = [];
    foreach ($this->rows as $blob) {
      $p = unserialize($blob);
      if ($p->status() === 'suspended' && $p->waiting_for() === $event_class) {
        $out[] = $p;
      }
    }
    return $out;
  }

  public function delete(int $id): void {
    unset($this->rows[$id]);
  }
}
