<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Symfony\Persistence\Time;
use TangibleDDD\Symfony\Persistence\TableNames;

/**
 * AW3: accepted outbox rows the relay delivered with no subscriber in any
 * consumer (`unheard_at`, noted by UnheardFactNotes), as layer `relay` of
 * the operator view. Not a failure (the fact was delivered), but the smell
 * FactDeliveredUnheard names: a renamed fact, a listener never wired up.
 * Key: the event id. No repairs. Oldest first; storage errors propagate.
 */
final class DbalUnheardFactSource implements IOperatorItemSource {

  private readonly string $table;

  public function __construct(
    private readonly Connection $connection,
    private readonly string $consumer,
    string $tablePrefix = '',
  ) {
    $this->table = TableNames::of($tablePrefix)->table('ddd_outbox');
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Relay)) {
      return [];
    }
    $rows = $this->connection->fetchAllAssociative(
      "SELECT event_id, integration_action, unheard_at FROM {$this->table}
        WHERE unheard_at IS NOT NULL AND status = 'accepted'
        ORDER BY unheard_at, id LIMIT ?",
      [$limit],
      [ParameterType::INTEGER]
    );

    return array_map(fn (array $r): OperatorItem => new OperatorItem(
      Layer::Relay,
      $this->consumer,
      (string) $r['event_id'],
      0,
      null,
      sprintf('delivered unheard: no subscriber for %s', $r['integration_action']),
      Time::from_db((string) $r['unheard_at']),
      [],
    ), $rows);
  }
}
