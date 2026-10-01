<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Symfony\Persistence\Time;

/**
 * `{prefix}ddd_wakeups` as layer `wakeup` of the operator view (D9, register
 * 3.10, 5.1): $consumer's intents that failed at least once, attempts against
 * the wake budget (10). An exhausted intent (never claimed again) carries the
 * repair `rearm` (`ddd:ops:stranded --rearm=<key>`); one still retrying has
 * none. Key: the intent's idempotency key. Oldest first; storage errors
 * propagate.
 */
final class DbalWakeupOperatorSource implements IOperatorItemSource {

  private readonly string $table;

  public function __construct(
    private readonly Connection $connection,
    private readonly string $consumer,
    string $tablePrefix = '',
    private readonly int $budget = WakeRetryPolicy::BUDGET,
  ) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_wakeups');
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Wakeup)) {
      return [];
    }
    $rows = $this->connection->fetchAllAssociative(
      "SELECT idempotency_key, attempts, last_error, exhausted_at, created_at FROM {$this->table}
        WHERE consumer = ? AND (exhausted_at IS NOT NULL OR attempts > 0)
        ORDER BY created_at, id LIMIT ?",
      [$this->consumer, $limit],
      [ParameterType::STRING, ParameterType::INTEGER]
    );

    return array_map(fn (array $r): OperatorItem => new OperatorItem(
      Layer::Wakeup,
      $this->consumer,
      (string) $r['idempotency_key'],
      (int) $r['attempts'],
      $this->budget,
      $r['last_error'] === null ? null : (string) $r['last_error'],
      Time::fromDb((string) $r['created_at']),
      $r['exhausted_at'] === null ? [] : ['rearm'],
    ), $rows);
  }
}
