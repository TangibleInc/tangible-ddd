<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Symfony\Persistence\TableNames;
use TangibleDDD\Symfony\Persistence\Time;

/**
 * `{prefix}ddd_delivery_ledger` as layer `delivery` of the operator view
 * (D9, register 3.10, 5.1): every (subscriber, fact) pair not delivered that
 * failed at least once or carries the exhausted marker, attempts against the
 * handler budget (`tangible_ddd.delivery.budget`). Key
 * `subscriber@event_id`, as the mem and pdo ledgers.
 *
 * No repair labels: a failing pair is still being retried by Messenger, and
 * an exhausted one has had its compensation (on_exhausted / failure_command);
 * its fact message, if Messenger gave up too, is in layer `transport`.
 * E3: an exhausted pair whose compensation sent a D1 failure command says
 * which and when (`exhausted: <error>; failure command <class> ran at <time>`,
 * noted by DeliveryNotes).
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
    $this->table = TableNames::of($tablePrefix)->table('ddd_delivery_ledger');
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Delivery)) {
      return [];
    }
    $rows = $this->connection->fetchAllAssociative(
      "SELECT subscriber_id, event_id, attempts, last_error, exhausted_at, failure_command, failure_command_at, updated_at FROM {$this->table}
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
      self::error_of($r),
      Time::from_db((string) $r['updated_at']),
      [],
    ), $rows);
  }

  /** @param array<string, mixed> $r */
  private static function error_of(array $r): ?string {
    if ($r['exhausted_at'] === null) {
      return $r['last_error'] === null ? null : (string) $r['last_error'];
    }
    $error = 'exhausted' . ($r['last_error'] === null ? '' : ': ' . $r['last_error']);
    if ($r['failure_command'] !== null) {
      $at = $r['failure_command_at'] === null ? null : Time::from_db((string) $r['failure_command_at']);
      $error .= sprintf('; failure command %s ran at %s', $r['failure_command'],
        $at?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM) ?? '?');
    }
    return $error;
  }
}
