<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Runtime\Process\StrandedProcess;

/**
 * In-memory IProcessStore. Rows hold a serialized copy, so find() never
 * returns the live instance (as a database would not). The stranded scan
 * reports `running`/`scheduled` rows older than the threshold that have NO
 * live intent (a pending, not completed or cancelled, intent for the process
 * id) in the attached InMemoryWakeupScheduler; with none attached it is
 * age-based only.
 */
final class InMemoryProcessStore implements IProcessStore, InMemoryTransactional {

  /**
   * @var array<int, array{blob: string, class: string, version: int, ignition_key: ?string,
   *   status: string, step_index: int, updated_at: \DateTimeImmutable, quarantine_reason: ?string}>
   */
  private array $rows = [];

  private int $nextId = 1;

  private ?InMemoryWakeupScheduler $intents;

  /**
   * @param InMemoryWakeupScheduler|null $intents the host's intents, so the
   *   stranded scan can honour "no live intent" (or attachIntents() later)
   */
  public function __construct(
    private readonly IClock $clock,
    private readonly int $strandedAfterSeconds = 900,
    ?InMemoryWakeupScheduler $intents = null,
  ) {
    $this->intents = $intents;
  }

  /** Join the stranded scan to these intents (the scheduler is often built after the store). */
  public function attachIntents(InMemoryWakeupScheduler $intents): void {
    $this->intents = $intents;
  }

  public function insertIgnited(LongProcess $p, string $processClass, string $eventId): IgnitionResult {
    $key = IgnitionKey::for($eventId, $processClass);
    foreach ($this->rows as $row) {
      if ($row['class'] === $processClass && $row['ignition_key'] === $key) {
        return IgnitionResult::AlreadyIgnited;
      }
    }
    $this->persistNew($p, $key, $processClass);
    return IgnitionResult::Inserted;
  }

  public function insert(LongProcess $p): int {
    return $this->persistNew($p, null, get_class($p));
  }

  public function find(int $id): ?LongProcess {
    $row = $this->rows[$id] ?? null;
    if ($row === null) {
      return null;
    }

    $process = @unserialize($row['blob']);
    if (!$process instanceof LongProcess) {
      $reason = "stored class {$row['class']} cannot be decoded";
      $this->rows[$id]['status'] = 'failed';
      $this->rows[$id]['quarantine_reason'] = $reason;
      throw new QuarantinedProcess("Process #$id quarantined: $reason");
    }
    return $process;
  }

  public function save(LongProcess $p, int $expectedVersion): int {
    $id = $p->get_id();
    if ($id === null || !isset($this->rows[$id])) {
      throw new ProcessStoreFailed('Cannot save process #' . ($id ?? 'null') . ': no such row');
    }
    $this->fence($id, $expectedVersion);

    $this->rows[$id] = [
      'blob' => serialize($p),
      'version' => $expectedVersion + 1,
      'status' => $p->status(),
      'step_index' => $p->current_step_index(),
      'updated_at' => $this->clock->now(),
    ] + $this->rows[$id];

    return $expectedVersion + 1;
  }

  public function touch(int $id, int $expectedVersion): int {
    if (!isset($this->rows[$id])) {
      throw new ProcessStoreFailed("Cannot touch process #$id: no such row");
    }
    $this->fence($id, $expectedVersion);
    $this->rows[$id]['version'] = $expectedVersion + 1;
    $this->rows[$id]['updated_at'] = $this->clock->now();
    return $expectedVersion + 1;
  }

  public function versionOf(int $id): ?int {
    return $this->rows[$id]['version'] ?? null;
  }

  public function findWaitingFor(string $eventClass, ?string $awaitKey = null): array {
    $ids = [];
    foreach ($this->rows as $id => $row) {
      if ($row['status'] !== 'suspended') {
        continue;
      }
      $p = @unserialize($row['blob']);
      $waiting = $p instanceof LongProcess ? $p->waiting_for() : null;
      if ($waiting !== null && ($waiting === $eventClass || is_a($eventClass, $waiting, true))) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  public function findStranded(\DateTimeImmutable $now): array {
    $cutoff = $now->modify("-{$this->strandedAfterSeconds} seconds");

    $live = [];
    foreach ($this->intents?->pending() ?? [] as $intent) {
      if ($intent->processId !== null) {
        $live[$intent->processId] = true;
      }
    }

    $out = [];
    foreach ($this->rows as $id => $row) {
      if (isset($live[$id])) {
        continue;
      }
      if (in_array($row['status'], ['running', 'scheduled'], true) && $row['updated_at'] <= $cutoff) {
        $out[] = new StrandedProcess($id, $row['class'], $row['status'], $row['step_index'], $row['updated_at']);
      }
    }
    return $out;
  }

  // ── inspection / test controls ────────────────────────────────────────────

  public function count(): int {
    return count($this->rows);
  }

  public function ignitionKeyOf(int $id): ?string {
    return $this->rows[$id]['ignition_key'] ?? null;
  }

  public function statusOf(int $id): ?string {
    return $this->rows[$id]['status'] ?? null;
  }

  public function quarantineReasonOf(int $id): ?string {
    return $this->rows[$id]['quarantine_reason'] ?? null;
  }

  /** Rewrite the stored class name so the row no longer decodes (decode.unknown-class). */
  public function corruptClassForTests(int $id, string $missingClass): void {
    $class = $this->rows[$id]['class'];
    $blob = $this->rows[$id]['blob'];
    $prefix = 'O:' . strlen($class) . ':"' . $class . '"';
    if (!str_starts_with($blob, $prefix)) {
      throw new \LogicException('Unexpected serialized form');
    }
    $this->rows[$id]['blob'] = 'O:' . strlen($missingClass) . ':"' . $missingClass . '"' . substr($blob, strlen($prefix));
    $this->rows[$id]['class'] = $missingClass;
  }

  public function snapshotState(): mixed {
    return [$this->rows, $this->nextId];
  }

  public function restoreState(mixed $state): void {
    [$this->rows, $this->nextId] = $state;
  }

  private function persistNew(LongProcess $p, ?string $ignitionKey, string $class): int {
    if ($p->get_id() !== null) {
      throw new ProcessStoreFailed('Process #' . $p->get_id() . ' is already persisted; use save()');
    }
    $id = $this->nextId++;
    $p->set_id($id);
    $this->rows[$id] = [
      'blob' => serialize($p),
      'class' => $class,
      'version' => 1,
      'ignition_key' => $ignitionKey,
      'status' => $p->status(),
      'step_index' => $p->current_step_index(),
      'updated_at' => $this->clock->now(),
      'quarantine_reason' => null,
    ];
    return $id;
  }

  private function fence(int $id, int $expectedVersion): void {
    if ($this->rows[$id]['version'] !== $expectedVersion) {
      throw new ConcurrentProcessModification(sprintf(
        'Process #%d is at version %d, expected %d',
        $id, $this->rows[$id]['version'], $expectedVersion
      ));
    }
  }
}
