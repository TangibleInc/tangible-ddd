<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IEffectJournal (D1; wave 4) on `{prefix}ddd_effect_journal`, on the host
 * connection: every write joins the caller's transaction when one is open
 * (invalidate() commits or rolls back with the repair command) and is an
 * autocommit statement otherwise (EffectMiddleware stores right after
 * perform(), outside any transaction, so the entry survives a rolled-back
 * record()).
 *
 * - find(): the recorded result while the row is not invalidated.
 * - store(): upsert; overwrites an existing key and clears an invalidation
 *   (the invalidation counter is kept).
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
final class PdoEffectJournal implements IEffectJournal {

  private readonly string $table;
  private readonly IClock $clock;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix = '', ?IClock $clock = null) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_effect_journal');
    $this->clock = $clock ?? new SystemClock();
  }

  public function find(string $key): ?EffectResult {
    $row = $this->guard('find', $key, fn () => $this->db->fetch_one(
      "SELECT result_json, external_ref FROM `{$this->table}` WHERE idempotency_key = ? AND invalidated_at IS NULL",
      [$key]
    ));
    if ($row === null) {
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
    $now = Utc::to_db($this->clock->now());
    $this->guard('store', $key, fn () => $this->db->execute(
      "INSERT INTO `{$this->table}` (idempotency_key, result_json, external_ref, performed_at) VALUES (?, ?, ?, ?) AS new
       ON DUPLICATE KEY UPDATE result_json = new.result_json, external_ref = new.external_ref,
         performed_at = new.performed_at, invalidated_at = NULL",
      [$key, $json, $r->external_ref, $now]
    ));
  }

  public function invalidate(string $key, string $reason): void {
    $this->guard('invalidate', $key, fn () => $this->db->execute(
      "UPDATE `{$this->table}` SET invalidated_at = ?, invalidation_reason = ?, invalidations = invalidations + 1
       WHERE idempotency_key = ? AND invalidated_at IS NULL",
      [Utc::to_db($this->clock->now()), $reason, $key]
    ));
  }

  private function guard(string $operation, string $key, callable $statement): mixed {
    try {
      return $statement();
    } catch (\Throwable $e) {
      throw new \RuntimeException("Effect journal $operation('$key') failed: " . $e->getMessage(), 0, $e);
    }
  }
}
