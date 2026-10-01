<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Scheduling\ICarriesFacts;

/**
 * The `{prefix}_ddd_wakeups` scheduler with the wave-5 fact-carrying opt-in
 * (TXP demand AW2): it declares ICarriesFacts, so the ProcessRunner parks a
 * fact resume that cannot take the process lock as a ResumeRetry intent
 * carrying the fact (column `fact`, schema v9) instead of failing the
 * resume subscriber. The answer is not retried as a listener delivery; the
 * intent fires on `{prefix}_ddd_wakeup` after the wake backoff and retries
 * on the wake budget (layer `wakeup`).
 *
 * WpHostPortFactory serves it to a consumer at schema v9. Behaviour is
 * otherwise WpdbWakeupScheduler's; that class alone keeps the wave-3 rule,
 * which conformance `lock.acquire-error` pins until it branches on
 * ICarriesFacts (CR-W5CC-7).
 */
final class WpdbParkingScheduler extends WpdbWakeupScheduler implements ICarriesFacts {
}
