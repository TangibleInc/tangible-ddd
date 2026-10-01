<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * The relay step of ddd-symfony: the CORE relay step,
 * `OutboxProcessor::process_batch($limit)` in its port form (register 1.4,
 * 3.4, 3.5, 5.1; CONF-3), run once per call. `ddd:relay` calls run_once().
 *
 * What the core step does (not repeated here): claim() outside any
 * transaction with the lease OutboxConfig::lock_timeout_seconds; submit at
 * the record's ABSOLUTE due_at; submit + accept in ONE boundary transaction
 * when the transport shares the store's connection, and a 0-row accept()
 * rolls that submission back (CR sfc-1); a throw or a missing reference
 * retries with the 5.1 backoff and dead-letters at max_attempts; the
 * per-event-id outcome lists (CR sfc-4); the OutboxAttemptFailed /
 * OutboxDeadLettered signals for the consumer.
 *
 * What this wrapper adds:
 *
 * - run_once($limit) passes $limit to process_batch() (CR sfc-3); null runs
 *   OutboxConfig::batch_size.
 * - Expired-lease dead letters made at claim time (CR sf-8, now the core
 *   rule CR-PDO-6) are taken, logged and signalled by the core step itself
 *   (DbalPostgresOutboxStore implements IReportsClaimDeadLetters,
 *   CR-W4CE-9); this wrapper only adds ProcessingResult::$claim_dead_letters
 *   to the report's dead_lettered list. It never signals them a second time.
 * - between_submit_and_accept(): the core test seam, for conformance.
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

  public static function backoff_seconds(int $failedAttempts, OutboxConfig $config): int {
    return OutboxProcessor::backoff_seconds($failedAttempts, $config);
  }

  /**
   * Test seam (core CONF-3): $hook runs after each successful submit, before
   * its accept; a throw from it propagates out of run_once() unchanged and is
   * not counted as an attempt. null removes it.
   *
   * @param (\Closure(\TangibleDDD\Runtime\Outbox\Claim, ?string): void)|null $hook
   */
  public function between_submit_and_accept(?\Closure $hook): void {
    $this->betweenSubmitAndAccept = $hook;
  }

  public function run_once(?int $limit = null): RelayReport {
    $processor = new OutboxProcessor(
      $this->consumer,
      null,
      $this->config,
      null,
      null,
      $this->logger,
      $this->clock,
      $this->outbox,
      $this->transport,
      $this->boundary,
    );
    $processor->between_submit_and_accept($this->betweenSubmitAndAccept);

    $result = $processor->process_batch($limit);

    return RelayReport::of($result, $result->claim_dead_letters);
  }
}
