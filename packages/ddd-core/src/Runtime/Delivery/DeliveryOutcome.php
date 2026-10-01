<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * What one IntegrationDelivery::deliver() call did, by subscriber id.
 *
 * - delivered: ran and succeeded in this call.
 * - skipped:   already delivered earlier (ledger hit), not run.
 * - failed:    threw in this call and is still under budget, OR is over
 *              budget with its compensation still pending (the on_exhausted
 *              callback threw, now or on an earlier crash): retry the fact.
 * - exhausted: over budget AND compensated (terminal ledger marker written,
 *              now or earlier); neither handler nor callback runs again.
 *
 * The delivery runner retries the fact while needs_retry(); each retry runs
 * only the subscribers that have not been delivered.
 *
 * UNRATIFIED: four lists where the register has {delivered, failed}; see
 * CR-2 in Runtime/API-CHANGE-REQUESTS.md.
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

  public function needs_retry(): bool {
    return $this->failed !== [];
  }

  /** Every subscriber is delivered (none failed, none exhausted). */
  public function is_complete(): bool {
    return $this->failed === [] && $this->exhausted === [];
  }
}
