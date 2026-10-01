<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/**
 * An IWakeupScheduler whose stored intents keep WakeupIntent::$fact: what
 * schedule() received comes back from claim_due() with the same `fact`
 * array (TXP demand AW2, wave 5). A host adds one nullable JSON column for
 * it (pdo `{prefix}_ddd_jobs`, wp `{prefix}_ddd_wakeups`, sf `ddd_wakeups`).
 *
 * The ProcessRunner parks a fact resume that cannot take the process lock
 * as a fact-carrying ResumeRetry only on a scheduler that declares this;
 * on any other scheduler the resume subscriber fails and the delivery
 * invoker retries the fact on the subscriber's budget (wave 3).
 */
interface ICarriesFacts extends IWakeupScheduler {
}
