<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * The `{prefix}ddd_delivery_ledger` table's contribution to the operator
 * view (register 3.10, 5.1; wave3-core CR-W3C-5, W3C-R5), layer `delivery`:
 * every (subscriber, event) pair not delivered that failed at least once or
 * carries the exhausted marker. Key `subscriber@event_id` (as the mem
 * ledger), attempts against the handler budget (5); repair `redeliver`
 * while the subscriber is retrying, none once it is exhausted (its
 * compensation ran).
 *
 * Oldest first (updated_at), at most $limit items. Storage errors propagate.
 */
final class PdoLedgerOperatorSource implements IOperatorItemSource {

  private readonly string $ledger;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly string $consumer,
    string $tablePrefix = '',
    private readonly int $budget = IntegrationDelivery::DEFAULT_BUDGET,
  ) {
    $this->ledger = (new PrefixedTableNames($tablePrefix))->table('ddd_delivery_ledger');
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Delivery)) {
      return [];
    }
    $rows = $this->db->fetchAll(
      "SELECT subscriber_id, event_id, attempts, last_error, exhausted_at, updated_at FROM `{$this->ledger}`
       WHERE delivered_at IS NULL AND (attempts > 0 OR exhausted_at IS NOT NULL)
       ORDER BY updated_at, subscriber_id, event_id LIMIT ?",
      [$limit]
    );

    return array_map(fn (array $r): OperatorItem => new OperatorItem(
      Layer::Delivery,
      $this->consumer,
      $r['subscriber_id'] . '@' . $r['event_id'],
      (int) $r['attempts'],
      $this->budget,
      $r['last_error'] === null ? null : (string) $r['last_error'],
      Utc::fromDb((string) $r['updated_at']),
      $r['exhausted_at'] === null ? ['redeliver'] : [],
    ), $rows);
  }
}
