<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;

/**
 * The `{prefix}ddd_jobs` table's contribution to the operator view
 * (register 3.10, 5.1; wave3-core CR-W3C-5, W3C-R5): every job that failed
 * at least once.
 *
 * - layer `wakeup`: continue / timeout / resume_retry intents, attempts
 *   against the wake budget (10), repair `retry_wake`;
 * - layer `delivery`: `deliver` jobs (key `deliver:{event_id}`), attempts
 *   against the handler budget (5). The per-subscriber verdicts are in the
 *   ledger (PdoLedgerOperatorSource); this row is the fact still queued.
 *
 * Oldest first (created_at), at most $limit items. Storage errors propagate.
 */
final class PdoJobsOperatorSource implements IOperatorItemSource {

  private readonly string $jobs;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly string $consumer,
    string $tablePrefix = '',
    private readonly int $wakeBudget = WakeRetryPolicy::BUDGET,
    private readonly int $deliveryBudget = IntegrationDelivery::DEFAULT_BUDGET,
  ) {
    $this->jobs = (new PrefixedTableNames($tablePrefix))->table('ddd_jobs');
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Wakeup && $layer !== Layer::Delivery)) {
      return [];
    }
    $kindSql = match ($layer) {
      Layer::Wakeup => " AND kind <> 'deliver'",
      Layer::Delivery => " AND kind = 'deliver'",
      default => '',
    };
    $rows = $this->db->fetchAll(
      "SELECT idempotency_key, kind, attempts, last_error, created_at FROM `{$this->jobs}`
       WHERE attempts > 0$kindSql ORDER BY created_at, id LIMIT ?",
      [$limit]
    );

    return array_map(function (array $r): OperatorItem {
      $deliver = $r['kind'] === 'deliver';
      return new OperatorItem(
        $deliver ? Layer::Delivery : Layer::Wakeup,
        $this->consumer,
        (string) $r['idempotency_key'],
        (int) $r['attempts'],
        $deliver ? $this->deliveryBudget : $this->wakeBudget,
        $r['last_error'] === null ? null : (string) $r['last_error'],
        Utc::fromDb((string) $r['created_at']),
        $deliver ? [] : ['retry_wake'],
      );
    }, $rows);
  }
}
