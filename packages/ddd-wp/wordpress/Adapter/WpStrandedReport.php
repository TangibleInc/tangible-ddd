<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Process\StrandedProcess;

/** What one WpStrandedScan::run() did. */
final class WpStrandedReport {

  /**
   * @param list<int> $minted process ids that got a fresh Continue intent
   * @param list<int> $alreadyQueued process ids left alone: a legacy continue action is queued
   * @param list<StrandedProcess> $running reported for the operator view only
   */
  public function __construct(
    public readonly array $minted,
    public readonly array $alreadyQueued,
    public readonly array $running,
  ) {}
}
