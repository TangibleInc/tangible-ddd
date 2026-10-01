<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Runtime\Scheduling\ICarriesFacts;

/**
 * The jobs table with the wave-5 fact-carrying opt-in (TXP demand AW2): it
 * declares ICarriesFacts, so the ProcessRunner parks a fact resume that
 * cannot take the process lock as a ResumeRetry job carrying the fact
 * (`{prefix}ddd_job_facts`, schema 011) instead of failing the resume
 * subscriber. The answer never spends its delivery budget and is never
 * dead-lettered while its process waits; the job retries on the wake
 * budget and stays visible in the operator layer `wakeup`.
 *
 * DurableRuntime::compose() builds this one. Behaviour is otherwise
 * PdoJobStore's; that class alone keeps the wave-3 rule, which conformance
 * `lock.acquire-error` pins until it branches on ICarriesFacts (CR-W5CC-7).
 */
final class PdoParkingJobStore extends PdoJobStore implements ICarriesFacts {
}
