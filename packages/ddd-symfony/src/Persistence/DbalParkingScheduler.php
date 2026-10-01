<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use TangibleDDD\Runtime\Scheduling\ICarriesFacts;

/**
 * The ddd_wakeups scheduler with the wave-5 fact-carrying opt-in (TXP
 * demand AW2): it declares ICarriesFacts, so the ProcessRunner parks a fact
 * resume that cannot take the process lock as a ResumeRetry intent carrying
 * the fact (column `fact`, schema 011) instead of failing the resume
 * subscriber. The answer then never spends its delivery budget and is never
 * dead-lettered while its process waits; the intent retries on the wake
 * budget and stays visible in the operator layer `wakeup`.
 *
 * The bundle wires it as every consumer's `wakeup_scheduler`. Behaviour is
 * otherwise DbalWakeupScheduler's; that class alone keeps the wave-3 rule,
 * which conformance `lock.acquire-error` pins until it branches on
 * ICarriesFacts (CR-W5CC-7).
 */
final class DbalParkingScheduler extends DbalWakeupScheduler implements ICarriesFacts {
}
