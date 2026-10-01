<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Messenger\ProcessWakeupHandler;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;

/**
 * The wakeup half of the sf relay tick (register 3.6, 5.3): projects due
 * intent rows to the `ddd_wakeups` Messenger transport and runs the
 * stranded scan. `ddd:relay` calls run_once() after each outbox step.
 *
 * - Projection: claim_due(now, limit, lease) leases due intents; each lease
 *   is sent as one ProcessWakeupMessage with no DelayStamp (the intent is
 *   already due; no multi-day delays live in the transport, E section 7).
 *   A send failure retries the intent later (2 s x 2^n); a lost message or a
 *   crash after the claim leaves the row leased until the lease expires,
 *   and the next tick re-projects it.
 * - Stranded scan (at most once per $strandedScanSeconds): a `scheduled`
 *   row with no live intent gets a fresh Continue intent in its own
 *   transaction (continuation is stale-safe), projected in the same tick;
 *   a `running` row is logged and reported only, because re-running it
 *   would repeat step effects (repairs are operator commands).
 *
 * Must run outside an open transaction (claim_due refuses to join one).
 */
final class WakeupRelay implements IWakeupRelayStep {

  private ?\DateTimeImmutable $lastScan = null;

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly IWakeupScheduler $scheduler,
    private readonly IProcessStore $store,
    private readonly ITransactionBoundary $boundary,
    private readonly SenderInterface $sender,
    private readonly IClock $clock,
    private readonly string $consumer,
    private readonly int $leaseSeconds = 300,
    private readonly int $strandedScanSeconds = 60,
    ?LoggerInterface $logger = null,
    private readonly ?string $busName = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  public function run_once(int $limit = 50): WakeupRelayReport {
    [$requeued, $reported] = $this->scanStrandedIfDue();

    $projected = [];
    $failed = [];
    $now = $this->clock->now();
    foreach ($this->scheduler->claim_due($now, $limit, $this->leaseSeconds) as $claim) {
      $key = $claim->intent->key;
      try {
        $this->sender->send(new Envelope(
          ProcessWakeupMessage::from_claim($claim),
          $this->busName === null ? [] : [new BusNameStamp($this->busName)],
        ));
        $projected[] = $key;
      } catch (\Throwable $e) {
        $failed[] = $key;
        $delay = ProcessWakeupHandler::backoff_seconds($claim->attempts);
        $this->logger->warning("[ddd wakeup] sending $key to the wakeup transport failed, retrying in {$delay}s: {$e->getMessage()}");
        $this->scheduler->retry_later($claim, 'send failed: ' . $e->getMessage(), $now->modify("+{$delay} seconds"));
      }
    }

    return new WakeupRelayReport($projected, $failed, $requeued, $reported);
  }

  /** @return array{0: list<int>, 1: list<int>} */
  private function scanStrandedIfDue(): array {
    $now = $this->clock->now();
    if ($this->lastScan !== null && $now < $this->lastScan->modify("+{$this->strandedScanSeconds} seconds")) {
      return [[], []];
    }
    $this->lastScan = $now;

    $requeued = [];
    $reported = [];
    foreach ($this->store->find_stranded($now) as $s) {
      if ($s->status === 'scheduled') {
        $this->boundary->run(fn () => $this->scheduler->schedule(
          WakeupIntent::continuation($this->consumer, $s->process_id, $s->step_index, $now)
        ));
        $requeued[] = $s->process_id;
        $this->logger->warning("[ddd wakeup] process #{$s->process_id} ({$s->process_class}) was scheduled with no live intent; re-queued a Continue intent");
        continue;
      }
      $reported[] = $s->process_id;
      $this->logger->warning(sprintf(
        '[ddd wakeup] process #%d (%s) has been running since %s with no live intent; repair it with ddd:ops:stranded',
        $s->process_id, $s->process_class, $s->updated_at->format(DATE_ATOM)
      ));
    }
    return [$requeued, $reported];
  }
}
