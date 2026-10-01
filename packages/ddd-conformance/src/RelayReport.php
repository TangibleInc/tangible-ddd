<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** What one HostFixture::relay_once() did, by event id. */
final class RelayReport {

  /**
   * @param list<string> $claimed
   * @param list<string> $accepted     transport took it and IOutboxStore::accept matched
   * @param list<string> $retried      rejected (or no reference) and put back with retry_later
   * @param list<string> $dead_lettered rejected on the last relay attempt
   * @param list<string> $lease_lost   a fenced write matched 0 rows
   */
  public function __construct(
    public readonly array $claimed = [],
    public readonly array $accepted = [],
    public readonly array $retried = [],
    public readonly array $dead_lettered = [],
    public readonly array $lease_lost = [],
  ) {}
}
