<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * The delivery stage of Drain::run_once() (register 3.6, 5.1): run up to
 * $limit due fact deliveries (each through IntegrationDelivery, retrying
 * the failed subscribers of a fact with the handler-execution backoff) and
 * return how many were processed. pdo implements it over the `deliver` job
 * rows of `{prefix}_ddd_jobs`; hosts whose transport delivers by itself (AS
 * on wp, Messenger on sf) need none. Wave 3 addition (wave3-core CR-W3C-4).
 *
 * Error behaviour: a failing subscriber is recorded in the ledger and is
 * not an error of the stage; storage failures throw (the drain logs them
 * and continues with its next stage). Runs outside any open transaction.
 */
interface IDeliveryWorker {

  public function run_due(\DateTimeImmutable $now, int $limit): int;
}
