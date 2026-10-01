<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Infra\Services\ProcessingResult;
use TangibleDDD\Runtime\Process\StrandedScanReport;

/** What one Drain::runOnce() pass did (register 3.6). */
final class DrainReport {

  public const STOPPED_IDLE = 'idle';
  public const STOPPED_MAX_ITEMS = 'max_items';
  public const STOPPED_MAX_SECONDS = 'max_seconds';

  /**
   * @param ProcessingResult|null $relay  the relay step's result; null when it did not run
   * @param int $delivered                 deliveries the delivery stage processed
   * @param list<string> $wakesCompleted   intent keys run (or found stale) and completed
   * @param list<string> $wakesRetried     intent keys re-queued with the wake backoff
   * @param list<string> $wakesExhausted   re-queued keys whose attempts reached the wake budget (still kept)
   * @param list<string> $wakesLeaseLost   keys whose complete()/retryLater() matched 0 rows
   * @param StrandedScanReport|null $stranded the stranded scan; null when it did not run
   * @param int $items                     relay claims + deliveries + wakes
   * @param string $stoppedBy              idle | max_items | max_seconds
   * @param list<string> $leaks            RuntimeReset leak reports (each was cleaned)
   * @param list<string> $errors           stage failures (logged; the pass continued)
   */
  public function __construct(
    public readonly ?ProcessingResult $relay = null,
    public readonly int $delivered = 0,
    public readonly array $wakesCompleted = [],
    public readonly array $wakesRetried = [],
    public readonly array $wakesExhausted = [],
    public readonly array $wakesLeaseLost = [],
    public readonly ?StrandedScanReport $stranded = null,
    public readonly int $items = 0,
    public readonly string $stoppedBy = self::STOPPED_IDLE,
    public readonly array $leaks = [],
    public readonly array $errors = [],
  ) {}
}
