<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Conformance\RelayReport;
use TangibleDDD\Conformance\SimulatedCrash;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Support\Log;

/**
 * WAVE-1 STAND-IN for the relay step of the core OutboxProcessor
 * (register 1.4 split: "`process_batch()` is the relay step of
 * `runOnce`"), which lands with the wave-2 move; the 0.6 processor reads
 * the WordPress IOutboxRepository (api change request CONF-3). Built only
 * on the ports, following register 3.4, 3.5 and 5.1:
 *
 * - claim() outside any transaction, lease = OutboxConfig::lock_timeout_seconds;
 * - submit at the record's ABSOLUTE due_at (no relative delay);
 * - a transport that shares the store's connection: submit + accept in ONE
 *   boundary transaction; otherwise submit, then accept;
 * - a throw, or a submission that yields no reference, is a rejection: the
 *   row is never accepted; retryLater with 60 s × 2^(n-1) capped at
 *   3600 s, and deadLetter once attempts reach the record's max_attempts;
 * - a fenced write that matches 0 rows is a lost lease: logged, discarded.
 *
 * Null reference: ITransport allows null only for "transports that have
 * none" but gives the relay no way to tell (CONF-4); every wave-1 transport
 * issues references, so null is treated as a rejection here.
 */
final class PortRelay {

  private bool $crashAfterSubmit = false;

  /** @param (\Closure(string):void)|null $log */
  public function __construct(
    private readonly IOutboxStore $outbox,
    private readonly ITransport $transport,
    private readonly ITransactionBoundary $boundary,
    private readonly IClock $clock,
    private readonly OutboxConfig $config = new OutboxConfig(),
    private readonly ?\Closure $log = null,
  ) {}

  public function crashNextAfterSubmit(): void {
    $this->crashAfterSubmit = true;
  }

  public static function backoffSeconds(int $failedAttempts, OutboxConfig $config): int {
    $n = max(1, $failedAttempts) - 1;
    $delay = $config->base_retry_delay_seconds * ($config->retry_multiplier ** $n);
    return (int) min($config->max_retry_delay_seconds, $delay);
  }

  public function runOnce(int $limit): RelayReport {
    $now = $this->clock->now();
    $claims = $this->outbox->claim($limit, $now, $this->config->lock_timeout_seconds);
    $claimed = $accepted = $retried = $dlq = $lost = [];

    foreach ($claims as $claim) {
      $claimed[] = $claim->event_id;
      try {
        $ok = $this->transport->sharesConnectionWith($this->outbox)
          ? $this->boundary->run(fn () => $this->submitAndAccept($claim))
          : $this->submitAndAccept($claim);
      } catch (SimulatedCrash $crash) {
        throw $crash;
      } catch (\Throwable $e) {
        $attempts = $claim->attempts + 1;
        $final = $attempts >= $claim->record->max_attempts;
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

    if ($this->crashAfterSubmit) {
      $this->crashAfterSubmit = false;
      throw new SimulatedCrash("relay died after submitting {$claim->event_id}, before accept");
    }

    if ($ref === null || $ref === '' || $ref === '0') {
      throw new TransportRejected("transport returned no reference for {$claim->event_id}");
    }

    return $this->outbox->accept($claim, $ref);
  }

  private function lostLease(Claim $claim, string $what): void {
    Log::write($this->log, "[ddd relay] lease lost on {$what} of {$claim->event_id}; result discarded");
  }
}
