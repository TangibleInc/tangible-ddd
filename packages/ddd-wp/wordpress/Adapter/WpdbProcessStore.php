<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Runtime\Process\StrandedProcess;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp IProcessStore on schema v8 (register 3.7, 3.8, 5.3; X7 and the
 * ruling on #76), over `{prefix}_long_processes` of the framework's own
 * ProcessRepository (whose 0.6 column mapping and hydration it reuses).
 *
 * - insert_ignited(): the #[StartsOn] path only. Inside the ignition lock
 *   (`ddd_ign_` + md5(prefix|class|event_id), the name the 0.6.7 hotfix also
 *   takes) it first looks for a row of the class that a 0.6 copy ignited
 *   with this event (ignited_by_event_id = event, start_path NULL: written
 *   between a rollback and a roll-forward), then INSERTs with
 *   ignition_key = uuid5(event_id, process_class) under
 *   UNIQUE (process_class, ignition_key). Either hit, or a duplicate key
 *   (MySQL 1062 only), returns AlreadyIgnited and persists nothing. An
 *   event id that is not a UUID cannot be keyed: its check, under the same
 *   lock, also counts N's own ignitions (start_path NULL or `ignition`,
 *   never `manual`), so a redelivery still ignites once; such an ignition
 *   is logged.
 * - insert(): manual start, ignition_key NULL, start_path `manual`, never
 *   deduped (process.manual-start-in-drain), so it never blocks a later
 *   #[StartsOn] ignition of its class by the same fact.
 * - save()/touch(): `WHERE id = ? AND version = ?`, version + 1; 0 rows
 *   throws ConcurrentProcessModification (or ProcessStoreFailed for an
 *   unknown id). A 0.6 copy writing during a deploy does not bump the
 *   version, so its writes are not fenced: a known cost of the mixed window.
 * - find(): an undecodable row (class gone, constructor changed) is
 *   quarantined: status `failed`, quarantine_reason set (R5, no new status),
 *   then QuarantinedProcess is thrown; the worker continues.
 * - find_stranded($now): `scheduled` / `running` rows not updated for
 *   $strandedAfterSeconds (default 900) with no live (`pending` / `firing`)
 *   intent in `{prefix}_ddd_wakeups`; a `running` row only while no other
 *   session holds its process lock on either name (IS_USED_LOCK; this
 *   session's own hold, the WP8-10 repair guard's, does not count).
 *
 * Every write failure throws ProcessStoreFailed (C27). Time is the host
 * IClock (constructor, else HostDefaults, else SystemClock).
 */
final class WpdbProcessStore implements IProcessStore {

  public function __construct(
    private readonly ProcessRepository $repository,
    private readonly IDDDConfig $config,
    private readonly ?IClock $clock = null,
    private readonly int $strandedAfterSeconds = 900,
  ) {}

  public function insert_ignited(LongProcess $p, string $processClass, string $eventId): IgnitionResult {
    try {
      $key = IgnitionKey::for($eventId, $processClass);
    } catch (\InvalidArgumentException) {
      $key = null;
    }

    $lock = 'ddd_ign_' . md5($this->config->prefix() . '|' . $processClass . '|' . $eventId);
    WpNamedLock::acquire($lock, 5);

    try {
      $db = self::db();
      // Keyed: only a 0.6 copy's ignition (start_path NULL) is invisible to
      // the UNIQUE key. Unkeyed: N's own earlier ignition must count too.
      $paths = $key === null ? "(start_path IS NULL OR start_path = 'ignition')" : 'start_path IS NULL';
      $seen = $db->get_var($db->prepare(
        "SELECT id FROM `{$this->table()}`
         WHERE process_class = %s AND ignited_by_event_id = %s AND $paths LIMIT 1",
        $processClass,
        $eventId
      ));
      if ($db->last_error !== '') {
        throw new ProcessStoreFailed("Ignition check for $processClass / $eventId failed: {$db->last_error}");
      }
      if ($seen !== null) {
        return IgnitionResult::AlreadyIgnited; // a 0.6 copy ignited it (rollback window), or an unkeyed redelivery
      }
      if ($key === null) {
        \TangibleDDD\Runtime\Support\Log::write(null, sprintf('[%s-process] ignition of %s by non-UUID event id %s: no ignition key, deduped by the ignited_by_event_id check under the ignition lock only', $this->config->prefix(), $processClass, $eventId), 'notice');
      }

      return $this->insertRow($p, $key, 'ignition') === null
        ? IgnitionResult::AlreadyIgnited
        : IgnitionResult::Inserted;
    } finally {
      WpNamedLock::release($lock);
    }
  }

  public function insert(LongProcess $p): int {
    return (int) $this->insertRow($p, null, 'manual');
  }

  public function find(int $id): ?LongProcess {
    $db = self::db();
    $row = $db->get_row($db->prepare("SELECT * FROM `{$this->table()}` WHERE id = %d", $id));
    if (!$row) {
      return null;
    }

    try {
      return $this->repository->hydrate_row($row);
    } catch (\Throwable $e) {
      $reason = sprintf('%s: %s (stored class %s)', get_class($e), $e->getMessage(), (string) $row->process_class);
      if ($row->status === 'failed' && ($row->quarantine_reason ?? null) !== null) {
        // Already quarantined: no second write, no version churn.
        throw new QuarantinedProcess("Process #$id is quarantined: {$row->quarantine_reason}", 0, $e);
      }
      $ok = $db->query($db->prepare(
        "UPDATE `{$this->table()}` SET status = 'failed', quarantine_reason = %s, version = version + 1, updated_at = %s WHERE id = %d",
        $reason,
        $this->stamp(),
        $id
      ));
      if ($ok === false) {
        throw new ProcessStoreFailed("Process #$id does not decode ($reason) and its quarantine was not written: " . (string) $db->last_error, 0, $e);
      }
      throw new QuarantinedProcess("Process #$id was quarantined: $reason", 0, $e);
    }
  }

  public function save(LongProcess $p, int $expectedVersion): int {
    $id = $p->get_id();
    if ($id === null) {
      throw new ProcessStoreFailed('Cannot save an unpersisted process; use insert()');
    }

    $row = $this->repository->row_for($p);
    $row['updated_at'] = $this->stamp();
    $db = self::db();
    $sets = [];
    $values = [];
    foreach ($row as $column => $value) {
      if ($value === null) {
        $sets[] = "`$column` = NULL";
        continue;
      }
      $sets[] = "`$column` = " . (is_int($value) ? '%d' : '%s');
      $values[] = $value;
    }
    $n = $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET " . implode(', ', $sets) . ", version = version + 1 WHERE id = %d AND version = %d",
      ...[...$values, $id, $expectedVersion]
    ));

    return $this->fenced($n, $id, $expectedVersion, 'save');
  }

  public function touch(int $id, int $expectedVersion): int {
    $db = self::db();
    $n = $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET version = version + 1 WHERE id = %d AND version = %d",
      $id,
      $expectedVersion
    ));
    return $this->fenced($n, $id, $expectedVersion, 'touch');
  }

  public function version_of(int $id): ?int {
    $db = self::db();
    $v = $db->get_var($db->prepare("SELECT version FROM `{$this->table()}` WHERE id = %d", $id));
    return $v === null ? null : (int) $v;
  }

  public function find_waiting_for(string $eventClass, ?string $awaitKey = null): array {
    $db = self::db();
    $ids = $db->get_col($db->prepare(
      "SELECT id FROM `{$this->table()}` WHERE waiting_for = %s AND status = 'suspended' ORDER BY id ASC",
      $eventClass
    ));
    return array_map('intval', is_array($ids) ? $ids : []);
  }

  public function find_stranded(\DateTimeImmutable $now): array {
    $db = self::db();
    $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))->modify("-{$this->strandedAfterSeconds} seconds")->format('Y-m-d H:i:s');
    $wakeups = $this->config->table('ddd_wakeups');
    $rows = $db->get_results($db->prepare(
      "SELECT p.id, p.process_class, p.status, p.step_index, p.updated_at FROM `{$this->table()}` p
       WHERE p.status IN ('scheduled', 'running') AND p.updated_at <= %s
         AND NOT EXISTS (SELECT 1 FROM `{$wakeups}` w WHERE w.process_id = p.id AND w.status IN ('pending', 'firing'))
       ORDER BY p.id ASC",
      $cutoff
    ));

    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
      // Register 5.3: a `running` row is stranded only when its lock is
      // free; a long wake that holds it (either name: N or a 0.6 copy, in
      // another session) is still running. This session's own hold is the
      // WP8-10 repair guard re-reading the row under the lock.
      if ($r->status === 'running') {
        $key = new \TangibleDDD\Runtime\Lock\LockKey($this->config->prefix(), '', (int) $r->id);
        if (!WpNamedLock::is_free_or_mine(GetLockProcessLock::name($key), GetLockProcessLock::legacy_name($key))) {
          continue;
        }
      }
      $out[] = new StrandedProcess(
        (int) $r->id,
        (string) $r->process_class,
        (string) $r->status,
        (int) $r->step_index,
        new \DateTimeImmutable((string) $r->updated_at, new \DateTimeZone('UTC')),
      );
    }
    return $out;
  }

  /** @return int|null the new id, or null when the ignition key is already taken */
  private function insertRow(LongProcess $p, ?string $ignitionKey, string $startPath): ?int {
    if ($p->get_id() !== null) {
      throw new ProcessStoreFailed('Process #' . $p->get_id() . ' is already persisted; use save()');
    }

    $row = $this->repository->row_for($p);
    $row['updated_at'] = $row['created_at'] = $this->stamp();
    $row['version'] = 1;
    $row['ignition_key'] = $ignitionKey;
    $row['start_path'] = $startPath;

    $db = self::db();
    $suppress = $db->suppress_errors(true);
    $ok = $db->insert($this->table(), $row);
    $db->suppress_errors($suppress);

    if ($ok === false) {
      if ($ignitionKey !== null && self::isDuplicateKey($db)) {
        return null;
      }
      throw new ProcessStoreFailed('Process insert failed for ' . get_class($p) . ': ' . (string) $db->last_error);
    }

    $id = (int) $db->insert_id;
    if ($id <= 0) {
      throw new ProcessStoreFailed('Process insert returned no id for ' . get_class($p));
    }
    $p->set_id($id);
    return $id;
  }

  private function fenced(mixed $affected, int $id, int $expectedVersion, string $what): int {
    if ($affected === false) {
      throw new ProcessStoreFailed("Process #$id $what failed: " . (string) self::db()->last_error);
    }
    if ((int) $affected === 1) {
      return $expectedVersion + 1;
    }
    $current = $this->version_of($id);
    if ($current === null) {
      throw new ProcessStoreFailed("Process #$id does not exist ($what)");
    }
    throw new ConcurrentProcessModification("Process #$id is at version $current, not $expectedVersion ($what): another holder advanced it");
  }

  /** MySQL error 1062 only, never the SQLSTATE class 23000 (register 3.3). */
  private static function isDuplicateKey(\wpdb $db): bool {
    $dbh = $db->dbh ?? null;
    if ($dbh instanceof \mysqli) {
      return mysqli_errno($dbh) === 1062;
    }
    return str_starts_with((string) $db->last_error, 'Duplicate entry');
  }

  private function stamp(): string {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now()
      ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  }

  private function table(): string {
    return $this->config->table('long_processes');
  }

  private static function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
