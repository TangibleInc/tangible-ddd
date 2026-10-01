<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/**
 * The stranded scan every relay tick runs (register 5.3 step 5).
 * ProcessRunner implements it over IProcessStore::find_stranded(); Drain
 * calls it once per run_once(). Wave 3 addition (wave3-core CR-W3C-3).
 *
 * Error behaviour: store and scheduler errors propagate; a re-queue is one
 * transaction per process.
 */
interface IStrandedScanner {

  public function scan_stranded(\DateTimeImmutable $now): StrandedScanReport;
}
