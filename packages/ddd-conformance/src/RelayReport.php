<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** What one HostFixture::relayOnce() did, by event id. */
final class RelayReport {

  /**
   * @param list<string> $claimed
   * @param list<string> $accepted     transport took it and IOutboxStore::accept matched
   * @param list<string> $retried      rejected (or no reference) and put back with retryLater
   * @param list<string> $deadLettered rejected on the last relay attempt
   * @param list<string> $leaseLost    a fenced write matched 0 rows
   */
  public function __construct(
    public readonly array $claimed = [],
    public readonly array $accepted = [],
    public readonly array $retried = [],
    public readonly array $deadLettered = [],
    public readonly array $leaseLost = [],
  ) {}
}
