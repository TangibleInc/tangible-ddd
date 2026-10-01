<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

/** One wakeup relay step (projection + stranded scan), as `ddd:relay` runs it. sf-local. */
interface IWakeupRelayStep {

  public function run_once(int $limit = 50): WakeupRelayReport;
}
