<?php

namespace TangibleDDD\Infra\Services;

/**
 * Result of a batch processing run.
 *
 * The four counts are the 0.6 shape. The trailing lists (CR sfc-4, wave 3)
 * name the event ids behind each outcome; they default to empty, so the
 * counts-only constructor stays valid. `lease_lost` rows are in `claimed`
 * and in no other list (their fenced write matched 0 rows).
 */
final class ProcessingResult {

  /**
   * @param list<string> $claimed      every row this run leased (port form) or fetched (0.6 form)
   * @param list<string> $accepted     handed to the transport and marked accepted / completed
   * @param list<string> $retried      rejected and rescheduled with backoff
   * @param list<string> $dead_lettered rejected for the last time and moved to the DLQ
   * @param list<string> $lease_lost   a fenced write matched 0 rows; the result was discarded
   * @param list<string> $claim_dead_letters dead-lettered by claim() itself because re-claims of
   *   expired leases reached max_attempts (CR-PDO-6, IReportsClaimDeadLetters); never in
   *   `claimed`, not counted in `dlq`/`total`
   */
  public function __construct(
    public readonly int $completed,
    public readonly int $failed,
    public readonly int $dlq,
    public readonly int $total,
    public readonly array $claimed = [],
    public readonly array $accepted = [],
    public readonly array $retried = [],
    public readonly array $dead_lettered = [],
    public readonly array $lease_lost = [],
    public readonly array $claim_dead_letters = [],
  ) {}
}
