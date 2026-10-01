<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/**
 * Result of one stranded scan (register 5.3 step 5): `scheduled` rows with
 * no live intent got a fresh Continue intent (continuation is stale-safe);
 * `running` rows are reported only, because re-running them would repeat
 * step effects. They surface in the operator view (layer `process`).
 */
final class StrandedScanReport {

  /**
   * @param list<int> $requeued process ids that got a Continue intent
   * @param list<StrandedProcess> $reported `running` rows left for an operator
   */
  public function __construct(
    public readonly array $requeued = [],
    public readonly array $reported = [],
  ) {}
}
