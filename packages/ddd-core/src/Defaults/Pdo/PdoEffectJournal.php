<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\Effects\EffectEntry;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IEffectJournal (D1; wave 4) with entry states (ITracksEffectState, E2,
 * wave 5) on `{prefix}ddd_effect_journal` and `{prefix}ddd_effect_recorded`
 * (schema 010), on the host connection: every write joins the caller's
 * transaction when one is open (invalidate() commits or rolls back with the
 * repair command, mark_recorded() with record()) and is autocommit
 * otherwise (EffectMiddleware stores right after perform(), outside any
 * transaction, so the entry survives a rolled-back record()).
 *
 * - find(): the result while the row is not invalidated, whatever its state.
 * - store(): upsert; overwrites an existing key, clears an invalidation (the
 *   invalidation counter is kept) and makes the entry Performed again (its
 *   recorded row is deleted, in one transaction with the upsert).
 * - mark_recorded(): writes the recorded row of a live entry (recorded_at =
 *   now); an unknown or invalidated key is a no-op.
 * - find_entry() / find_unrecorded(): the entry with its state; both skip
 *   invalidated rows. find_unrecorded() = no recorded row and performed_at
 *   before the cut-off, oldest first.
 * - invalidate(): marks the row invalidated (find() then answers null, so
 *   the effect performs again), keeping the reason and a counter for the
 *   operator. An unknown or already-invalidated key is a no-op.
 *
 * No failure-command trigger of its own: the core invoker fires
 * failure_command() from the delivery ledger budget (register 5.1).
 * Errors: storage failures (including a key over 191 characters, the
 * column) and a corrupt row throw \RuntimeException. Same contract as
 * ddd-symfony's DbalEffectJournal.
 */
final class PdoEffectJournal implements ITracksEffectState {

  private readonly string $table;
  private readonly string $recorded;
  private readonly IClock $clock;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix = '', ?IClock $clock = null) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->table = $tables->table('ddd_effect_journal');
    $this->recorded = $tables->table('ddd_effect_recorded');
    $this->clock = $clock ?? new SystemClock();
  }

  public function find(string $key): ?EffectResult {
    return $this->find_entry($key)?->result;
  }

  public function store(string $key, EffectResult $r): void {
    try {
      $json = json_encode((object) $r->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    } catch (\JsonException $e) {
      throw new \RuntimeException("Effect result for '$key' does not encode as JSON: " . $e->getMessage(), 0, $e);
    }
    $now = Utc::to_db($this->clock->now());
    $this->guard('store', $key, fn () => $this->atomically(function () use ($key, $json, $r, $now): void {
      $this->db->execute("DELETE FROM `{$this->recorded}` WHERE idempotency_key = ?", [$key]);
      $this->db->execute(
        "INSERT INTO `{$this->table}` (idempotency_key, result_json, external_ref, performed_at) VALUES (?, ?, ?, ?) AS new
         ON DUPLICATE KEY UPDATE result_json = new.result_json, external_ref = new.external_ref,
           performed_at = new.performed_at, invalidated_at = NULL",
        [$key, $json, $r->external_ref, $now]
      );
    }));
  }

  public function invalidate(string $key, string $reason): void {
    $this->guard('invalidate', $key, fn () => $this->db->execute(
      "UPDATE `{$this->table}` SET invalidated_at = ?, invalidation_reason = ?, invalidations = invalidations + 1
       WHERE idempotency_key = ? AND invalidated_at IS NULL",
      [Utc::to_db($this->clock->now()), $reason, $key]
    ));
  }

  public function mark_recorded(string $key): void {
    $now = Utc::to_db($this->clock->now());
    $this->guard('mark_recorded', $key, fn () => $this->db->execute(
      "INSERT INTO `{$this->recorded}` (idempotency_key, recorded_at)
       SELECT j.idempotency_key, ? FROM `{$this->table}` j WHERE j.idempotency_key = ? AND j.invalidated_at IS NULL
       ON DUPLICATE KEY UPDATE recorded_at = ?",
      [$now, $key, $now]
    ));
  }

  public function find_entry(string $key): ?EffectEntry {
    $row = $this->guard('find', $key, fn () => $this->db->fetch_one(
      "SELECT j.idempotency_key, j.result_json, j.external_ref, j.performed_at, r.recorded_at
         FROM `{$this->table}` j LEFT JOIN `{$this->recorded}` r ON r.idempotency_key = j.idempotency_key
        WHERE j.idempotency_key = ? AND j.invalidated_at IS NULL",
      [$key]
    ));
    return $row === null ? null : self::entry($row);
  }

  public function find_unrecorded(\DateTimeImmutable $performed_before, int $limit): array {
    if ($limit <= 0) {
      return [];
    }
    $rows = $this->guard('find_unrecorded', '*', fn () => $this->db->fetch_all(
      "SELECT j.idempotency_key, j.result_json, j.external_ref, j.performed_at, NULL AS recorded_at
         FROM `{$this->table}` j LEFT JOIN `{$this->recorded}` r ON r.idempotency_key = j.idempotency_key
        WHERE r.idempotency_key IS NULL AND j.invalidated_at IS NULL AND j.performed_at < ?
        ORDER BY j.performed_at, j.idempotency_key
        LIMIT ?",
      [Utc::to_db($performed_before), $limit]
    ));
    return array_map(self::entry(...), $rows);
  }

  /** @param array<string, mixed> $row */
  private static function entry(array $row): EffectEntry {
    $key = (string) $row['idempotency_key'];
    try {
      $data = json_decode((string) $row['result_json'], true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      throw new \RuntimeException("Effect journal entry '$key' does not decode: " . $e->getMessage(), 0, $e);
    }
    if (!is_array($data)) {
      throw new \RuntimeException("Effect journal entry '$key' is not a JSON object.");
    }
    $recorded = Utc::from_db_or_null($row['recorded_at']);
    return new EffectEntry(
      $key,
      new EffectResult($data, $row['external_ref'] === null ? null : (string) $row['external_ref']),
      $recorded === null ? EffectState::Performed : EffectState::Recorded,
      Utc::from_db((string) $row['performed_at']),
      $recorded,
    );
  }

  /** Run $work in the caller's transaction, or in one of its own. */
  private function atomically(callable $work): void {
    if ($this->db->in_transaction()) {
      $work();
      return;
    }
    $this->db->begin();
    try {
      $work();
      $this->db->commit();
    } catch (\Throwable $e) {
      if ($this->db->in_transaction()) {
        $this->db->rollback();
      }
      throw $e;
    }
  }

  private function guard(string $operation, string $key, callable $statement): mixed {
    try {
      return $statement();
    } catch (\Throwable $e) {
      throw new \RuntimeException("Effect journal $operation('$key') failed: " . $e->getMessage(), 0, $e);
    }
  }
}
