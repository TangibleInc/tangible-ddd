<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Symfony\Persistence\Time;

/**
 * `{prefix}ddd_delivery_ledger` as layer `delivery` of the operator view
 * (D9, register 3.10, 5.1): every (subscriber, fact) pair not delivered that
 * failed at least once or carries the exhausted marker, attempts against the
 * handler budget (`tangible_ddd.delivery.budget`). Key
 * `subscriber@event_id`, as the mem and pdo ledgers.
 *
 * No repair labels: a failing pair is still being retried by Messenger, and
 * an exhausted one has had its compensation (onExhausted / failureCommand);
 * its fact message, if Messenger gave up too, is in layer `transport`.
 * The sf ledger is per consumer database, so every row is $consumer's.
 * Oldest first (updated_at); storage errors propagate.
 */
final class DbalLedgerOperatorSource implements IOperatorItemSource {

  private readonly string $table;

  public function __construct(
    private readonly Connection $connection,
    private readonly string $consumer,
    string $tablePrefix = '',
    private readonly int $budget = IntegrationDelivery::DEFAULT_BUDGET,
  ) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_delivery_ledger');
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Delivery)) {
      return [];
    }
    $rows = $this->connection->fetchAllAssociative(
      "SELECT subscriber_id, event_id, attempts, last_error, exhausted_at, updated_at FROM {$this->table}
        WHERE delivered_at IS NULL AND (attempts > 0 OR exhausted_at IS NOT NULL)
        ORDER BY updated_at, subscriber_id, event_id LIMIT ?",
      [$limit],
      [ParameterType::INTEGER]
    );

    return array_map(fn (array $r): OperatorItem => new OperatorItem(
      Layer::Delivery,
      $this->consumer,
      $r['subscriber_id'] . '@' . $r['event_id'],
      (int) $r['attempts'],
      $this->budget,
      $r['exhausted_at'] === null
        ? ($r['last_error'] === null ? null : (string) $r['last_error'])
        : 'exhausted' . ($r['last_error'] === null ? '' : ': ' . $r['last_error']),
      Time::fromDb((string) $r['updated_at']),
      [],
    ), $rows);
  }
}
