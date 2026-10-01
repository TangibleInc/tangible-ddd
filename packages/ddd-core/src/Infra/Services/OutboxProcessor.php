<?php

namespace TangibleDDD\Infra\Services;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Infrastructure\FactDeliveredUnheard;
use TangibleDDD\Application\Infrastructure\OutboxAttemptFailed;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\SystemClock;
use Throwable;

/**
 * The transactional-outbox RELAY — transport/persistence mechanics (fetch →
 * publish → mark, locking, retry, DLQ), no domain content. Run periodically
 * (cron, Action Scheduler, a worker loop, runOnce).
 *
 * Two forms (register 1.4, 5.1; CONF-3):
 *
 * - Port form, the relay step of runOnce: constructed with an IOutboxStore
 *   and an ITransport (repository and publisher null). process_batch():
 *   claim() due rows OUTSIDE any transaction with the lease from
 *   OutboxConfig::lock_timeout_seconds; submit each at its ABSOLUTE due_at
 *   (no relative delay, bug 3); a transport that shares the store's
 *   connection runs submit + accept in ONE boundary transaction. A throw,
 *   or a reference of null / '' / '0' (CONF-4), is a rejection:
 *   retryLater() with base × multiplier^(n-1) capped at the max delay, or
 *   deadLetter() once attempts reach the record's max_attempts. A fenced
 *   write matching 0 rows is a lost lease: logged and discarded. On a
 *   shared connection a 0-row accept() also rolls the submission back
 *   (CR sfc-1), so the new lease holder's submission is the only one.
 *   process_batch(?int $limit) overrides the batch size for one run
 *   (CR sfc-3); ProcessingResult lists the event ids per outcome (CR sfc-4).
 *   between_submit_and_accept() is the test seam (a hook that throws aborts
 *   the batch at exactly that point; it is never counted as an attempt).
 * - 0.6 form, unchanged for shipped containers: (config, IOutboxRepository,
 *   OutboxConfig, IOutboxPublisher), over fetch_pending / mark_* / move_to_dlq.
 *
 * Optional trailing parameters (R2): ISubscriberProbe (has_action on wp;
 * null = unknown, no unheard diagnostic), LoggerInterface (host logger, else
 * error_log for problems), IClock, then the port collaborators. Both forms
 * emit the same signals (OutboxDeadLettered, OutboxAttemptFailed,
 * FactDeliveredUnheard) and log `[{prefix}-outbox] STATUS: {json}`; a
 * success is logged at debug level only (0.6: only under WP_DEBUG).
 */
final class OutboxProcessor {

  private string $worker_id;

  private ?\Closure $between_submit_and_accept = null;

  private ?Throwable $interruption = null;

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly ?IOutboxRepository $outbox,
    private readonly OutboxConfig $outbox_config,
    private readonly ?IOutboxPublisher $publisher,
    private readonly ?ISubscriberProbe $probe = null,
    private readonly ?LoggerInterface $logger = null,
    private readonly ?IClock $clock = null,
    private readonly ?IOutboxStore $store = null,
    private readonly ?ITransport $transport = null,
    private readonly ?ITransactionBoundary $boundary = null,
  ) {
    $ports = $store !== null && $transport !== null;
    $legacy = $outbox !== null && $publisher !== null;
    if (!$ports && !$legacy) {
      throw new \InvalidArgumentException(
        'OutboxProcessor needs an IOutboxStore and an ITransport (port form) or an IOutboxRepository and an IOutboxPublisher (0.6 form).'
      );
    }
    $this->worker_id = gethostname() . '-' . getmypid();
  }

  /** Relay backoff (5.1): base × multiplier^(n-1) seconds, capped; $failed_attempts >= 1. */
  public static function backoff_seconds(int $failed_attempts, OutboxConfig $config): int {
    $n = max(1, $failed_attempts) - 1;
    $delay = $config->base_retry_delay_seconds * ($config->retry_multiplier ** $n);
    return (int) min($config->max_retry_delay_seconds, $delay);
  }

  /**
   * Test seam (CONF-3): run $hook after each successful submit and before
   * its accept. A throw from the hook propagates out of process_batch()
   * unchanged and is not recorded as an attempt (a simulated crash).
   */
  public function between_submit_and_accept(?\Closure $hook): void {
    $this->between_submit_and_accept = $hook;
  }

  /**
   * Process a batch of pending outbox entries.
   *
   * @param int|null $limit rows for this run (CR sfc-3); null = OutboxConfig::batch_size
   */
  public function process_batch(?int $limit = null): ProcessingResult {
    $limit = $limit === null ? $this->outbox_config->batch_size : max(0, $limit);
    return $this->store !== null && $this->transport !== null
      ? $this->relay_batch($limit)
      : $this->legacy_batch($limit);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Port form
  // ─────────────────────────────────────────────────────────────────────────

  private function relay_batch(int $limit): ProcessingResult {
    $now = $this->clock()->now();
    $claims = $limit > 0
      ? $this->store->claim($limit, $now, $this->outbox_config->lock_timeout_seconds)
      : [];

    $completed = $failed = $dlq = 0;
    $ids = ['claimed' => [], 'accepted' => [], 'retried' => [], 'dead' => [], 'lost' => []];

    foreach ($claims as $claim) {
      $ids['claimed'][] = $claim->event_id;
      $unheard = $this->probe()?->hasSubscribers($claim->record->integration_action) === false;

      try {
        if ($this->transport->sharesConnectionWith($this->store) && $this->boundary() !== null) {
          // CR sfc-1: on a shared connection a 0-row accept must roll the
          // submission back with it, or the new lease holder submits the
          // same fact a second time. Throw inside run(), catch outside.
          $this->boundary()->run(function () use ($claim): void {
            if (!$this->submit_and_accept($claim)) {
              throw new LeaseLostOnAccept($claim->event_id);
            }
          });
          $accepted = true;
        } else {
          $accepted = $this->submit_and_accept($claim);
        }
      } catch (LeaseLostOnAccept) {
        $accepted = false;
      } catch (Throwable $e) {
        if ($e === $this->interruption) {
          $this->interruption = null;
          throw $e;
        }

        $attempts = $claim->attempts + 1;
        $entry = OutboxEntry::from_claim($claim, 'pending', $e->getMessage());

        if ($attempts >= $claim->record->max_attempts) {
          if (!$this->store->deadLetter($claim, $e->getMessage())) {
            $this->lost_lease($claim, 'dead-letter');
            $ids['lost'][] = $claim->event_id;
            continue;
          }
          $dlq++;
          $ids['dead'][] = $claim->event_id;
          $this->log_event('dlq', $entry, $e->getMessage());
          (new OutboxDeadLettered($entry, $e->getMessage()))->dispatch($this->config);
        } else {
          $next = $now->modify('+' . self::backoff_seconds($attempts, $this->outbox_config) . ' seconds');
          if (!$this->store->retryLater($claim, $e->getMessage(), $next)) {
            $this->lost_lease($claim, 'retry');
            $ids['lost'][] = $claim->event_id;
            continue;
          }
          $failed++;
          $ids['retried'][] = $claim->event_id;
          $this->log_event('failed', $entry, $e->getMessage());
          (new OutboxAttemptFailed($entry, $attempts, $claim->record->max_attempts, $e->getMessage()))->dispatch($this->config);
        }
        continue;
      }

      if (!$accepted) {
        $this->lost_lease($claim, 'accept');
        $ids['lost'][] = $claim->event_id;
        continue;
      }

      $completed++;
      $ids['accepted'][] = $claim->event_id;
      $entry = OutboxEntry::from_claim($claim, 'accepted');
      $this->log_event('completed', $entry);
      if ($unheard) {
        // Observability, not failure: the fact was still delivered.
        (new FactDeliveredUnheard($entry))->dispatch($this->config);
      }
    }

    return new ProcessingResult(
      $completed, $failed, $dlq, count($claims),
      $ids['claimed'], $ids['accepted'], $ids['retried'], $ids['dead'], $ids['lost'],
    );
  }

  private function submit_and_accept(Claim $claim): bool {
    $r = $claim->record;
    $ref = $this->transport->submit(
      $claim,
      IntegrationEnvelope::wrap($r->payload, $r->correlation_id, $r->sequence, $r->event_id),
      $r->due_at,
    );

    if ($this->between_submit_and_accept !== null) {
      try {
        ($this->between_submit_and_accept)($claim, $ref);
      } catch (Throwable $e) {
        $this->interruption = $e;
        throw $e;
      }
    }

    if ($ref === null || $ref === '' || $ref === '0') {
      throw new TransportRejected("Transport returned no reference for {$claim->event_id}; every transport must issue one (CONF-4).");
    }

    return $this->store->accept($claim, $ref);
  }

  private function lost_lease(Claim $claim, string $what): void {
    Log::write($this->logger, sprintf(
      '[%s-outbox] lease lost on %s of %s (claim %s); result discarded',
      $this->config->prefix(), $what, $claim->event_id, $claim->claimToken
    ));
  }

  // ─────────────────────────────────────────────────────────────────────────
  // 0.6 form
  // ─────────────────────────────────────────────────────────────────────────

  private function legacy_batch(int $limit): ProcessingResult {
    // First, release any stale locks from crashed workers
    $this->outbox->release_stale_locks($this->outbox_config->lock_timeout_seconds);

    // Fetch pending entries (acquires lock). Paused event types are excluded by
    // the repository itself, so this returns nothing (or fewer rows) while paused.
    $entries = $limit > 0 ? $this->outbox->fetch_pending($limit, $this->worker_id) : [];

    if (empty($entries)) {
      return new ProcessingResult(0, 0, 0, 0);
    }

    $completed = 0;
    $failed = 0;
    $dlq = 0;
    $ids = ['claimed' => [], 'accepted' => [], 'retried' => [], 'dead' => []];

    foreach ($entries as $entry) {
      $ids['claimed'][] = $entry->event_id;
      try {
        // Delivered-to-nobody check happens BEFORE firing: the probe reads
        // the listener table as it stands at drain time. The contract is
        // unchanged either way — an unheard fact is still delivered.
        $unheard = $this->probe()?->hasSubscribers($entry->integration_action) === false;

        $wrapped = $this->wrap_payload_for_transport($entry);
        $this->publisher->publish($entry, $wrapped);
        $this->outbox->mark_completed($entry->event_id);
        $completed++;
        $ids['accepted'][] = $entry->event_id;

        $this->log_event('completed', $entry);

        if ($unheard) {
          // Observability, not failure: status stays completed. Fires
          // {prefix}_fact_delivered_unheard + the global
          // tangible_ddd_fact_delivered_unheard on WordPress.
          (new FactDeliveredUnheard($entry))->dispatch($this->config);

          // Best-effort, additive: the note method lives on the concrete
          // repository, NOT on IOutboxRepository — a consumer-authored
          // implementation predating this release must not fatal here.
          if (method_exists($this->outbox, 'note_delivered_unheard')) {
            $this->outbox->note_delivered_unheard(
              $entry->event_id,
              sprintf('delivered with zero listeners on "%s"', $entry->integration_action)
            );
          }
        }

      } catch (Throwable $e) {
        $new_attempts = $entry->attempts + 1;

        if ($new_attempts >= $entry->max_attempts) {
          // Pass the TRUE final error: the final attempt skips mark_failed, so
          // the row's last_error is the prior attempt's. This is the exception
          // that actually caused the dead-letter.
          $this->outbox->move_to_dlq($entry->event_id, $e->getMessage());
          $dlq++;
          $ids['dead'][] = $entry->event_id;
          $this->log_event('dlq', $entry, $e->getMessage());

          // Infrastructure event — terminal failure, out-of-band. Carries the
          // dead event's correlation + event_id so a listener (e.g. escalation)
          // rejoins the original trace.
          (new OutboxDeadLettered($entry, $e->getMessage()))->dispatch($this->config);
        } else {
          $this->outbox->mark_failed($entry->event_id, $e->getMessage());
          $failed++;
          $ids['retried'][] = $entry->event_id;
          $this->log_event('failed', $entry, $e->getMessage());

          // Infrastructure event — transient retry-pressure signal (metrics),
          // not an alert. Event stays queued for the next attempt.
          (new OutboxAttemptFailed($entry, $new_attempts, $entry->max_attempts, $e->getMessage()))->dispatch($this->config);
        }
      }
    }

    return new ProcessingResult(
      $completed, $failed, $dlq, count($entries),
      $ids['claimed'], $ids['accepted'], $ids['retried'], $ids['dead'],
    );
  }

  /**
   * Wrap payload with correlation context for downstream tracing.
   *
   * Delegates to the envelope — wrap() and unwrap() are one codec in one
   * home (0.2.5); this processor no longer knows the __-key wire format.
   */
  private function wrap_payload_for_transport(OutboxEntry $entry): array {
    return IntegrationEnvelope::wrap(
      $entry->payload,
      $entry->correlation_id,
      $entry->sequence,
      $entry->event_id,
    );
  }

  /**
   * One log line per processed entry: `[{prefix}-outbox] STATUS: {json}`.
   * Success is debug (the 0.6 "only under WP_DEBUG" rule is the logger's
   * level filter); failed and dlq are warnings. Encoded with json_encode,
   * never the WordPress encoder (unguarded outside WordPress in 0.6).
   */
  private function log_event(string $status, OutboxEntry $entry, ?string $error = null): void {
    $context = [
      'event_id' => $entry->event_id,
      'event_type' => $entry->event_type,
      'correlation_id' => $entry->correlation_id,
      'attempts' => $entry->attempts,
      'worker_id' => $this->worker_id,
    ];

    if ($error) {
      $context['error'] = $error;
    }

    Log::write(
      $this->logger,
      sprintf(
        '[%s-outbox] %s: %s',
        $this->config->prefix(),
        strtoupper($status),
        json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
      ),
      $status === 'completed' ? 'debug' : 'warning'
    );
  }

  private function probe(): ?ISubscriberProbe {
    return $this->probe ?? HostDefaults::get(ISubscriberProbe::class);
  }

  private function boundary(): ?ITransactionBoundary {
    return $this->boundary ?? HostDefaults::get(ITransactionBoundary::class);
  }

  private function clock(): IClock {
    return $this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock();
  }
}
