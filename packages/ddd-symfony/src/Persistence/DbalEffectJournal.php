<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\SystemClock;

/**
 * IEffectJournal (D1) on `{prefix}ddd_effect_journal`, on the domain DBAL
 * connection, so every write joins the caller's transaction when one is open
 * (invalidate() commits or rolls back with the repair command) and is an
 * autocommit statement otherwise.
 *
 * - find(): the recorded result while the row is not invalidated.
 * - store(): upsert; overwrites an existing key and clears an invalidation.
 * - invalidate(): marks the row invalidated (find() then answers null, so the
 *   effect performs again), keeping the reason and a counter for the
 *   operator. An unknown or already-invalidated key is a no-op.
 *
 * No failure-command trigger of its own: the core invoker fires
 * failure_command() from the delivery ledger budget (register 5.1).
 * Errors: storage failures throw \RuntimeException with the DBAL exception as
 * previous; a corrupt row (result_json not a JSON object) too.
 */
final class DbalEffectJournal implements IEffectJournal {

  private readonly string $table;
  private readonly IClock $clock;

  public function __construct(private readonly Connection $connection, ?IClock $clock = null, string $tablePrefix = '') {
    $this->table = TableNames::of($tablePrefix)->table('ddd_effect_journal');
    $this->clock = $clock ?? new SystemClock();
  }

  public function find(string $key): ?EffectResult {
    $row = $this->guard('find', $key, fn () => $this->connection->fetchAssociative(
      "SELECT result_json, external_ref FROM {$this->table} WHERE idempotency_key = ? AND invalidated_at IS NULL",
      [$key]
    ));
    if ($row === false) {
      return null;
    }
    try {
      $data = json_decode((string) $row['result_json'], true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      throw new \RuntimeException("Effect journal entry '$key' does not decode: " . $e->getMessage(), 0, $e);
    }
    if (!is_array($data)) {
      throw new \RuntimeException("Effect journal entry '$key' is not a JSON object.");
    }
    return new EffectResult($data, $row['external_ref'] === null ? null : (string) $row['external_ref']);
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
             performed_at = EXCLUDED.performed_at, invalidated_at = NULL",
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

  private function guard(string $operation, string $key, callable $statement): mixed {
    try {
      return $statement();
    } catch (\Doctrine\DBAL\Exception $e) {
      throw new \RuntimeException("Effect journal $operation('$key') failed: " . $e->getMessage(), 0, $e);
    }
  }
}
