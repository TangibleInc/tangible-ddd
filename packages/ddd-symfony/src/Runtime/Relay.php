<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * ROUND-1 relay step of ddd-symfony, built only on the ports (register 3.4,
 * 3.5, 5.1). Wave-2 round 3 replaces its body with the core relay step
 * (`OutboxProcessor::process_batch` over IOutboxStore + ITransport +
 * ITransactionBoundary + IClock, CONF-3); `ddd:relay` keeps calling runOnce().
 *
 * - claim() outside any transaction, lease = OutboxConfig::lock_timeout_seconds;
 * - submit at the record's ABSOLUTE due_at (no relative delay, bug 3);
 * - a transport that shares the store's connection: submit + accept in ONE
 *   boundary transaction (exactly-once hand-off); otherwise submit, then
 *   accept (at-least-once);
 * - a throw, or no reference, is a rejection: never accepted; retryLater with
 *   60 s x 2^(n-1) capped at 3600 s (OutboxConfig), deadLetter once attempts
 *   reach the record's max_attempts;
 * - a fenced write that matches 0 rows is a lost lease: logged, discarded.
 *   On a shared connection a lost lease on accept ROLLS BACK the submission
 *   as well (the Messenger insert), so the row's new holder is the only one
 *   that delivers it. (The wave-1 conformance PortRelay commits it; the core
 *   relay step must not: CR sf-3.)
 */
final class Relay {

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly IOutboxStore $outbox,
    private readonly ITransport $transport,
    private readonly ITransactionBoundary $boundary,
    private readonly IClock $clock,
    private readonly OutboxConfig $config = new OutboxConfig(),
    ?LoggerInterface $logger = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  public static function backoffSeconds(int $failedAttempts, OutboxConfig $config): int {
    $n = max(1, $failedAttempts) - 1;
    $delay = $config->base_retry_delay_seconds * ($config->retry_multiplier ** min($n, 32));
    return (int) min($config->max_retry_delay_seconds, $delay);
  }

  public function runOnce(?int $limit = null): RelayReport {
    $now = $this->clock->now();
    $claims = $this->outbox->claim($limit ?? $this->config->batch_size, $now, $this->config->lock_timeout_seconds);
    $claimed = $accepted = $retried = $dlq = $lost = [];
    $shared = $this->transport->sharesConnectionWith($this->outbox);

    foreach ($claims as $claim) {
      $claimed[] = $claim->event_id;
      try {
        $ok = $shared
          ? $this->boundary->run(function () use ($claim): bool {
            // A lost lease inside the shared transaction must roll the
            // submission back too, or the row's new holder submits it again.
            if (!$this->submitAndAccept($claim)) {
              throw new LeaseLostOnAccept($claim->event_id);
            }
            return true;
          })
          : $this->submitAndAccept($claim);
      } catch (LeaseLostOnAccept) {
        $ok = false;
      } catch (\Throwable $e) {
        $attempts = $claim->attempts + 1;
        $final = $attempts >= $claim->record->max_attempts;
        $this->logger->warning(sprintf(
          '[ddd relay] submission of %s failed (attempt %d/%d): %s',
          $claim->event_id, $attempts, $claim->record->max_attempts, $e->getMessage()
        ), ['exception' => $e]);
        $ok = $final
          ? $this->outbox->deadLetter($claim, $e->getMessage())
          : $this->outbox->retryLater($claim, $e->getMessage(), $now->modify('+' . self::backoffSeconds($attempts, $this->config) . ' seconds'));
        if (!$ok) {
          $lost[] = $claim->event_id;
          $this->lostLease($claim, 'failure write');
        } elseif ($final) {
          $dlq[] = $claim->event_id;
        } else {
          $retried[] = $claim->event_id;
        }
        continue;
      }

      if ($ok) {
        $accepted[] = $claim->event_id;
      } else {
        $lost[] = $claim->event_id;
        $this->lostLease($claim, 'accept');
      }
    }

    return new RelayReport($claimed, $accepted, $retried, $dlq, $lost);
  }

  private function submitAndAccept(Claim $claim): bool {
    $r = $claim->record;
    $ref = $this->transport->submit(
      $claim,
      IntegrationEnvelope::wrap($r->payload, $r->correlation_id, $r->sequence, $r->event_id),
      $r->due_at,
    );
    if ($ref === null || $ref === '' || $ref === '0') {
      throw new TransportRejected("transport returned no reference for {$claim->event_id}");
    }
    return $this->outbox->accept($claim, $ref);
  }

  private function lostLease(Claim $claim, string $what): void {
    $this->logger->warning("[ddd relay] lease lost on {$what} of {$claim->event_id}; result discarded");
  }
}
