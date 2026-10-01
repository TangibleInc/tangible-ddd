<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Scheduling\ICarriesFacts;

/**
 * The in-memory wakeup scheduler with the wave-5 fact-carrying opt-in
 * (TXP demand AW2): it declares ICarriesFacts, so the ProcessRunner parks
 * a fact resume that cannot take the process lock as a ResumeRetry wakeup
 * carrying the fact instead of failing the resume subscriber. Behaviour is
 * otherwise identical to InMemoryWakeupScheduler, which keeps the wave-3
 * rule until the conformance scenario lock.acquire-error branches on
 * ICarriesFacts (CR-W5CC-7).
 */
final class InMemoryParkingScheduler extends InMemoryWakeupScheduler implements ICarriesFacts {
}
