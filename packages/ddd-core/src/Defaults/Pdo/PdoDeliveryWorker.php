<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use Psr\Log\LoggerInterface;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryWorker;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Support\Log;

/**
 * IDeliveryWorker over the `deliver` rows of `{prefix}ddd_jobs` (register
 * 3.5, 3.6, 5.1; wave3-core CR-W3C-4, W3C-R5): the delivery stage of the pdo
 * Drain.
 *
 * run_due($now, $limit) leases up to $limit due deliver jobs (wakeup rows are
 * never claimed here), and for each one:
 *
 *   1. reads the fact (PdoJobStore::delivery_of) and its PHP class: the class
 *      the outbox writer recorded (FactClassRecordingEventBus), else the
 *      `$eventClasses` map (event type → class) DurableRuntime builds from
 *      the registered listeners and processes;
 *   2. hands it to the core invoker, IntegrationDelivery::deliver(), which
 *      skips subscribers already in the ledger, runs the rest in priority
 *      order (each command in its own transaction) and counts each failure
 *      against that subscriber's budget;
 *   3. completes (deletes) the job when nothing is left to retry, otherwise
 *      retry_later()s it with the handler backoff 30 s × 2^(n-1), capped at
 *      3600 s (IntegrationDelivery::backoff_seconds), n = the job's attempt.
 *
 * The ledger, not the job, holds the budget: once every failed subscriber is
 * exhausted the outcome needs no retry and the job completes. A job that
 * cannot be delivered at all (unknown class, decode error with budget left,
 * a ledger storage error) is retried with the same backoff and its error
 * kept in `last_error`, so it shows in the operator view (layer `delivery`)
 * and is never dropped.
 *
 * RuntimeReset::between_messages() runs after each job; a leak is logged.
 * Returns the number of jobs processed. Must be called outside any open
 * transaction (the claim refuses to join one).
 */
final class PdoDeliveryWorker implements IDeliveryWorker {

  private readonly PdoJobStore $deliveries;

  /** @param array<string, class-string<IIntegrationEvent>> $eventClasses event type (Event::name()) → fact class */
  public function __construct(
    PdoJobStore $jobs,
    private readonly IntegrationDelivery $delivery,
    private readonly array $eventClasses = [],
    private readonly int $leaseSeconds = 300,
    private readonly ?LoggerInterface $logger = null,
  ) {
    $this->deliveries = $jobs->claiming(WakeKind::Deliver);
  }

  public function run_due(\DateTimeImmutable $now, int $limit): int {
    if ($limit <= 0) {
      return 0;
    }
    $claimed = $this->deliveries->claim_due($now, $limit, $this->leaseSeconds);
    foreach ($claimed as $claim) {
      try {
        $this->deliverOne($claim, $now);
      } finally {
        $this->reset();
      }
    }
    return count($claimed);
  }

  private function deliverOne(ClaimedWakeup $claim, \DateTimeImmutable $now): void {
    $key = $claim->intent->key;
    try {
      $job = $this->deliveries->delivery_of($claim);
      if ($job === null) {
        $this->deliveries->complete($claim); // the row vanished under the lease; nothing to deliver
        return;
      }
      $outcome = $this->delivery->deliver($this->classOf($job), $job->envelope);
    } catch (\Throwable $e) {
      $this->retry($claim, $now, $e->getMessage());
      return;
    }

    if ($outcome->needs_retry()) {
      $this->retry($claim, $now, $this->describe($outcome));
      return;
    }
    if (!$this->deliveries->complete($claim)) {
      Log::write($this->logger, "[ddd delivery] lease lost on complete of $key; another worker owns it");
    }
  }

  /** @return class-string<IIntegrationEvent> */
  private function classOf(DeliveryJob $job): string {
    $class = $job->event_class ?? $this->eventClasses[$job->event_type] ?? null;
    if ($class === null) {
      throw new \UnexpectedValueException(
        "No event class for {$job->event_type} (event {$job->event_id}): the outbox row recorded none and no registered listener or process names it"
      );
    }
    if (!is_a($class, IIntegrationEvent::class, true)) {
      throw new \UnexpectedValueException("The event class $class of {$job->event_id} is not a loadable IIntegrationEvent");
    }
    return $class;
  }

  private function retry(ClaimedWakeup $claim, \DateTimeImmutable $now, string $error): void {
    $attempt = $claim->attempts + 1;
    $next = $now->modify('+' . IntegrationDelivery::backoff_seconds($attempt) . ' seconds');
    $key = $claim->intent->key;
    if ($this->deliveries->retry_later($claim, $error, $next)) {
      Log::write($this->logger, sprintf('[ddd delivery] %s attempt %d needs a retry at %s: %s', $key, $attempt, $next->format(DATE_ATOM), $error));
    } else {
      Log::write($this->logger, "[ddd delivery] lease lost on retry of $key: $error");
    }
  }

  private function describe(DeliveryOutcome $outcome): string {
    return 'subscribers to retry: ' . implode(', ', $outcome->failed);
  }

  private function reset(): void {
    try {
      RuntimeReset::between_messages();
    } catch (RuntimeLeakDetected $e) {
      Log::write($this->logger, '[ddd delivery] ' . $e->getMessage(), 'error');
    }
  }
}
