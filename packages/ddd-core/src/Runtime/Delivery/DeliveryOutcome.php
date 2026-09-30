<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * What one IntegrationDelivery::deliver() call did, by subscriber id.
 *
 * - delivered: ran and succeeded in this call.
 * - skipped:   already delivered earlier (ledger hit), not run.
 * - failed:    threw in this call and is still under budget: retry the fact.
 * - exhausted: at or over budget (now or earlier); not run again.
 *
 * The delivery runner retries the fact while needsRetry(); each retry runs
 * only the subscribers that have not been delivered.
 */
final class DeliveryOutcome {

  /**
   * @param list<string> $delivered
   * @param list<string> $skipped
   * @param list<string> $failed
   * @param list<string> $exhausted
   */
  public function __construct(
    public readonly array $delivered,
    public readonly array $skipped,
    public readonly array $failed,
    public readonly array $exhausted,
  ) {}

  public function needsRetry(): bool {
    return $this->failed !== [];
  }

  /** Every subscriber is delivered (none failed, none exhausted). */
  public function isComplete(): bool {
    return $this->failed === [] && $this->exhausted === [];
  }
}
