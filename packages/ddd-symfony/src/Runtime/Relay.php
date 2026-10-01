<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * The relay step of ddd-symfony: the CORE relay step,
 * `OutboxProcessor::process_batch()` in its port form (register 1.4, 3.4,
 * 3.5, 5.1; CONF-3), run once per call. `ddd:relay` calls runOnce().
 *
 * What the core step does (not repeated here): claim() outside any
 * transaction with the lease OutboxConfig::lock_timeout_seconds; submit at
 * the record's ABSOLUTE due_at; submit + accept in ONE boundary transaction
 * when the transport shares the store's connection; a throw or a missing
 * reference retries with the 5.1 backoff and dead-letters at max_attempts;
 * the OutboxAttemptFailed / OutboxDeadLettered signals for the consumer.
 *
 * What this wrapper adds, without re-implementing the loop:
 *
 * - runOnce($limit): the limit replaces OutboxConfig::batch_size for that step.
 * - A per-event-id RelayReport. The core step returns counts only, so the
 *   step runs over RelayOutcomes, a recording view of the store.
 * - CR sf-3: on a shared connection, a lost lease on accept rolls the
 *   submission back (RelayOutcomes throws LeaseLostOnAccept inside the
 *   transaction). The core step's follow-up retryLater/deadLetter for that
 *   claim is fenced too and matches 0 rows, so the row is reported `lost`.
 * - Expired-lease dead letters made at claim time by DbalPostgresOutboxStore
 *   (CR sf-8) appear in the report's deadLettered list and emit the same
 *   OutboxDeadLettered signal as a relay-side dead letter.
 * - betweenSubmitAndAccept(): the core test seam, for conformance.
 */
final class Relay {

  private readonly LoggerInterface $logger;

  private readonly IDDDConfig $consumer;

  private ?\Closure $betweenSubmitAndAccept = null;

  public function __construct(
    private readonly IOutboxStore $outbox,
    private readonly ITransport $transport,
    private readonly ITransactionBoundary $boundary,
    private readonly IClock $clock,
    private readonly OutboxConfig $config = new OutboxConfig(),
    ?LoggerInterface $logger = null,
    ?IDDDConfig $consumer = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
    $this->consumer = $consumer ?? new SymfonyConsumerConfig('ddd', 'App');
  }

  public static function backoffSeconds(int $failedAttempts, OutboxConfig $config): int {
    return OutboxProcessor::backoff_seconds($failedAttempts, $config);
  }

  /**
   * Test seam (core CONF-3): $hook runs after each successful submit, before
   * its accept; a throw from it propagates out of runOnce() unchanged and is
   * not counted as an attempt. null removes it.
   *
   * @param (\Closure(\TangibleDDD\Runtime\Outbox\Claim, ?string): void)|null $hook
   */
  public function betweenSubmitAndAccept(?\Closure $hook): void {
    $this->betweenSubmitAndAccept = $hook;
  }

  public function runOnce(?int $limit = null): RelayReport {
    $shared = $this->transport->sharesConnectionWith($this->outbox);
    $outcomes = new RelayOutcomes($this->outbox, $shared, $this->logger);

    $processor = new OutboxProcessor(
      $this->consumer,
      null,
      $this->configFor($limit ?? $this->config->batch_size),
      null,
      null,
      $this->logger,
      $this->clock,
      $outcomes,
      new RelayTransportView($this->transport, $this->outbox),
      $this->boundary,
    );
    $processor->between_submit_and_accept($this->betweenSubmitAndAccept);

    try {
      $processor->process_batch();
    } finally {
      $this->signalClaimDeadLetters($outcomes);
    }

    return $outcomes->report();
  }

  private function configFor(int $limit): OutboxConfig {
    if ($limit === $this->config->batch_size) {
      return $this->config;
    }
    return new OutboxConfig(...['batch_size' => max(0, $limit)] + get_object_vars($this->config));
  }

  private function signalClaimDeadLetters(RelayOutcomes $outcomes): void {
    foreach ($outcomes->claimDeadLetters() as [$claim, $error]) {
      (new OutboxDeadLettered(OutboxEntry::from_claim($claim, 'dlq', $error), $error))->dispatch($this->consumer);
    }
  }
}
