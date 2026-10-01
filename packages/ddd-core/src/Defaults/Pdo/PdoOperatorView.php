<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\OutboxAndQuarantineItems;
use TangibleDDD\Runtime\ConsumerPrefix;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\Ops\PortOperatorView;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Runtime\SystemClock;

/**
 * The pdo host's one failure view (register 3.10, 5.1, D9): read-only, one
 * list across the layers, attempts against budget, for the host to render
 * (no UI here). Since wave 3 round 2 it is the core PortOperatorView over
 * the pdo ports plus IOperatorItemSource adapters for the pdo tables
 * (W3C-R5, CR-PDO-4):
 *
 * - `relay`: DLQ rows (PdoOutboxAdministration::deadLetters; repairs retry,
 *   replay, discard) and `pending` rows that already failed a submission
 *   (repair retry). Budget: the row's max_attempts.
 * - `delivery`: ledger pairs failing or exhausted (PdoLedgerOperatorSource,
 *   key `subscriber@event_id`) and deliver jobs that failed
 *   (PdoJobsOperatorSource, key `deliver:{event_id}`). Budget 5.
 * - `wakeup`: intents that failed (PdoJobsOperatorSource). Budget 10.
 * - `process`: stranded `running` rows (PdoProcessStore::findStranded;
 *   repairs resume_stranded, fail_stranded) and quarantined rows.
 *
 * list() returns OperatorItems (IOperatorView); toArrays() returns their
 * array form (OperatorItem::toArray: snake_case keys, ISO 8601 UTC times),
 * which is what a raw-PHP host renders.
 */
final class PdoOperatorView implements IOperatorView {

  private readonly PortOperatorView $view;

  public function __construct(
    IHostConnection $db,
    string $consumer,
    string $tablePrefix = '',
    ?IClock $clock = null,
    int $deliveryBudget = IntegrationDelivery::DEFAULT_BUDGET,
    int $wakeBudget = WakeRetryPolicy::BUDGET,
    int $strandedAfterSeconds = 900,
  ) {
    $clock ??= new SystemClock();
    $this->view = new PortOperatorView(
      new ConsumerPrefix($consumer),
      new PdoOutboxAdministration($db, $tablePrefix, $clock),
      new PdoProcessStore($db, $tablePrefix, $clock, $strandedAfterSeconds),
      $clock,
      [
        new OutboxAndQuarantineItems($db, $consumer, $tablePrefix),
        new PdoLedgerOperatorSource($db, $consumer, $tablePrefix, $deliveryBudget),
        new PdoJobsOperatorSource($db, $consumer, $tablePrefix, $wakeBudget, $deliveryBudget),
      ],
    );
  }

  /** @return list<OperatorItem> */
  public function list(?Layer $layer = null, int $limit = 100): array {
    return $this->view->list($layer, $limit);
  }

  /**
   * @return list<array{layer: string, layer_label: string, consumer: string, key: string, attempts: int, budget: ?int,
   *   last_error: ?string, first_seen: ?string, repair_actions: list<string>}>
   */
  public function toArrays(?Layer $layer = null, int $limit = 100): array {
    return array_map(static fn (OperatorItem $item) => $item->toArray(), $this->list($layer, $limit));
  }
}
