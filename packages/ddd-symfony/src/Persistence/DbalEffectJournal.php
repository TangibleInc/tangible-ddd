<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Effects\EffectEntry;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\SystemClock;

/**
 * IEffectJournal (D1) with entry states (ITracksEffectState, E2, wave 5) on
 * `{prefix}ddd_effect_journal`, on the domain DBAL connection, so every
 * write joins the caller's transaction when one is open (invalidate()
 * commits or rolls back with the repair command, mark_recorded() with
 * record()) and is an autocommit statement otherwise.
 *
 * - find(): the result while the row is not invalidated, whatever its state.
 * - store(): upsert; overwrites an existing key, clears an invalidation and
 *   resets the entry to Performed (recorded_at NULL).
 * - mark_recorded(): sets recorded_at on a live row; an unknown or
 *   invalidated key is a no-op.
 * - find_entry() / find_unrecorded(): the entry with its state; both skip
 *   invalidated rows. find_unrecorded() = recorded_at IS NULL and
 *   performed_at before the cut-off, oldest first (schema 011's partial
 *   index).
 * - invalidate(): marks the row invalidated (find() then answers null, so the
 *   effect performs again), keeping the reason and a counter for the
 *   operator. An unknown or already-invalidated key is a no-op.
 *
 * Needs schema 011 (recorded_at). No failure-command trigger of its own: the
 * core invoker fires failure_command() from the delivery ledger budget
 * (register 5.1). Errors: storage failures throw \RuntimeException with the
 * DBAL exception as previous; a corrupt row (result_json not a JSON object)
 * too.
 */
final class DbalEffectJournal implements ITracksEffectState {

  private readonly string $table;
  private readonly IClock $clock;

  public function __construct(private readonly Connection $connection, ?IClock $clock = null, string $tablePrefix = '') {
    $this->table = TableNames::of($tablePrefix)->table('ddd_effect_journal');
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
    $this->guard('store', $key, fn () => $this->connection->executeStatement(
      "INSERT INTO {$this->table} (idempotency_key, result_json, external_ref, performed_at) VALUES (?, ?, ?, ?)
       ON CONFLICT (idempotency_key) DO UPDATE
         SET result_json = EXCLUDED.result_json, external_ref = EXCLUDED.external_ref,
             performed_at = EXCLUDED.performed_at, invalidated_at = NULL, recorded_at = NULL",
      [$key, $json, $r->external_ref, Time::to_db($this->clock->now())]
    ));
  }

  public function invalidate(string $key, string $reason): void {
    $this->guard('invalidate', $key, fn () => $this->connection->executeStatement(
      "UPDATE {$this->table}
         SET invalidated_at = ?, invalidation_reason = ?, invalidations = invalidations + 1
       WHERE idempotency_key = ? AND invalidated_at IS NULL",
      [Time::to_db($this->clock->now()), $reason, $key],
      [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING]
    ));
  }

  public function mark_recorded(string $key): void {
    $this->guard('mark_recorded', $key, fn () => $this->connection->executeStatement(
      "UPDATE {$this->table} SET recorded_at = ? WHERE idempotency_key = ? AND invalidated_at IS NULL",
      [Time::to_db($this->clock->now()), $key]
    ));
  }

  public function find_entry(string $key): ?EffectEntry {
    $row = $this->guard('find', $key, fn () => $this->connection->fetchAssociative(
      "SELECT idempotency_key, result_json, external_ref, performed_at, recorded_at
         FROM {$this->table} WHERE idempotency_key = ? AND invalidated_at IS NULL",
      [$key]
    ));
    return $row === false ? null : self::entry($row);
  }

  public function find_unrecorded(\DateTimeImmutable $performed_before, int $limit): array {
    if ($limit <= 0) {
      return [];
    }
    $rows = $this->guard('find_unrecorded', '*', fn () => $this->connection->fetchAllAssociative(
      "SELECT idempotency_key, result_json, external_ref, performed_at, recorded_at
         FROM {$this->table}
        WHERE recorded_at IS NULL AND invalidated_at IS NULL AND performed_at < ?
        ORDER BY performed_at, idempotency_key
        LIMIT ?",
      [Time::to_db($performed_before), $limit],
      [ParameterType::STRING, ParameterType::INTEGER]
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
    $recorded = $row['recorded_at'] === null ? null : Time::from_db((string) $row['recorded_at']);
    return new EffectEntry(
      $key,
      new EffectResult($data, $row['external_ref'] === null ? null : (string) $row['external_ref']),
      $recorded === null ? EffectState::Performed : EffectState::Recorded,
      Time::from_db((string) $row['performed_at']),
      $recorded,
    );
  }

  private function guard(string $operation, string $key, callable $statement): mixed {
    try {
      return $statement();
    } catch (\Doctrine\DBAL\Exception $e) {
      throw new \RuntimeException("Effect journal $operation('$key') failed: " . $e->getMessage(), 0, $e);
    }
  }
}
