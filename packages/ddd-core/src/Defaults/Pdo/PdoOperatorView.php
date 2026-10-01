<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\OperatorRepairs;
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
 * The pdo host's one failure view (register 3.10, 5.1, D9): one list across
 * the layers, attempts against budget, for the host to render (no UI here),
 * plus the operator repairs its items name (wave 4). The list is the core
 * PortOperatorView over the pdo ports plus IOperatorItemSource adapters for
 * the pdo tables (W3C-R5, CR-PDO-4):
 *
 * - `relay`: DLQ rows (PdoOutboxAdministration::dead_letters; repairs retry,
 *   replay, discard), including rows dead-lettered at claim after their
 *   lease expired max_attempts times (CR-PDO-6), and `pending` rows that
 *   already failed a submission (repair retry). Budget: the row's
 *   max_attempts.
 * - `delivery`: ledger pairs failing or exhausted (PdoLedgerOperatorSource,
 *   key `subscriber@event_id`) and deliver jobs that failed
 *   (PdoJobsOperatorSource, key `deliver:{event_id}`). Budget 5. Repair
 *   redeliver while retrying.
 * - `wakeup`: intents that failed (PdoJobsOperatorSource). Budget 10.
 *   Repair retry_wake.
 * - `process`: stranded `running` rows (PdoProcessStore::find_stranded;
 *   repairs resume_stranded, fail_stranded) and quarantined rows (none).
 *
 * list() returns OperatorItems (IOperatorView); to_arrays() their array form
 * (OperatorItem::to_array: snake_case keys, ISO 8601 UTC times).
 *
 * Repairs (register 3.10: transactional, with status and lease guards, C23):
 * repair($layer, $key, $action, $options) or repair_item($item, $action,
 * $options), which also refuses an action the item does not list.
 *
 * - relay retry   → PdoOutboxAdministration::retry($key, $options['force'])
 *   (refuses a leased row; a dead-lettered row leaves the DLQ);
 * - relay replay  → replay() of the event's newest DLQ row (same event_id);
 * - relay discard → discard() of the event's newest DLQ row;
 * - delivery redeliver → the fact's queued `deliver:{event_id}` job becomes
 *   due now (the delivery ledger keeps its attempts; subscribers already
 *   delivered are skipped); refused when the job is gone or leased, or the
 *   subscriber is exhausted (its compensation ran);
 * - wakeup retry_wake → the intent becomes due now (attempts kept); refused
 *   when it is gone or leased;
 * - process resume_stranded / fail_stranded → the core repair commands'
 *   handlers (ResumeStrandedProcessHandler / FailStrandedProcessHandler,
 *   WP8-10) on this connection: process lock taken with zero wait, the row
 *   re-read under it and checked against the stranded scan, version
 *   fenced; $options: `reason` (fail_stranded, required), `compensate`,
 *   `expected_version`.
 *
 * Refusals: PdoRepairRefused (pdo guards), OutboxAdministrationRefused /
 * OutboxRowNotFound (relay), ProcessNotStranded (process);
 * \InvalidArgumentException for an action the layer has not got, or a
 * missing option. Call repairs outside any open transaction or inside the
 * host's own; each runs in one transaction.
 */
final class PdoOperatorView implements IOperatorView {

  private readonly PortOperatorView $view;
  private readonly OperatorRepairs $repairs;

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
    $administration = new PdoOutboxAdministration($db, $tablePrefix, $clock);
    $processes = new PdoProcessStore($db, $tablePrefix, $clock, $strandedAfterSeconds);
    $this->view = new PortOperatorView(
      new ConsumerPrefix($consumer),
      $administration,
      $processes,
      $clock,
      [
        new OutboxAndQuarantineItems($db, $consumer, $tablePrefix),
        new PdoLedgerOperatorSource($db, $consumer, $tablePrefix, $deliveryBudget),
        new PdoJobsOperatorSource($db, $consumer, $tablePrefix, $wakeBudget, $deliveryBudget),
      ],
    );
    $this->repairs = new OperatorRepairs($db, $consumer, $tablePrefix, $clock, $administration, $processes);
  }

  /** @return list<OperatorItem> */
  public function list(?Layer $layer = null, int $limit = 100): array {
    return $this->view->list($layer, $limit);
  }

  /**
   * @return list<array{layer: string, layer_label: string, consumer: string, key: string, attempts: int, budget: ?int,
   *   last_error: ?string, first_seen: ?string, repair_actions: list<string>}>
   */
  public function to_arrays(?Layer $layer = null, int $limit = 100): array {
    return array_map(static fn (OperatorItem $item) => $item->to_array(), $this->list($layer, $limit));
  }

  /**
   * Run one operator repair on the item at ($layer, $key) (see the class
   * docblock for the actions and their guards).
   *
   * @param array{force?: bool, reason?: string, compensate?: bool, expected_version?: int} $options
   */
  public function repair(Layer $layer, string $key, string $action, array $options = []): void {
    $this->repairs->run($layer, $key, $action, $options);
  }

  /**
   * repair() for an item list() returned; refuses (PdoRepairRefused) an
   * action the item does not list in its repairs.
   *
   * @param array{force?: bool, reason?: string, compensate?: bool, expected_version?: int} $options
   */
  public function repair_item(OperatorItem $item, string $action, array $options = []): void {
    if (!in_array($action, $item->repairs, true)) {
      throw new PdoRepairRefused(sprintf(
        'The %s item %s offers %s, not %s',
        $item->layer->value, $item->key, $item->repairs === [] ? 'no repair' : implode(', ', $item->repairs), $action
      ));
    }
    $this->repairs->run($item->layer, $item->key, $action, $options);
  }
}
