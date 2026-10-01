<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

/**
 * D14 without NOTIFY (scenario wakeup.post-commit, the poll half): the
 * kernel variant `no_listen` (tangible_ddd.relay.listen: false) sends no
 * NOTIFY and ddd:relay sleeps between steps. A committed fact is still
 * relayed, within one poll interval: polling recovers a lost (here: never
 * sent) wakeup, so NOTIFY only shortens latency (register 5.3 step 3).
 */
final class PostCommitPollFallbackTest extends RelayWorkerTestBase {

  protected static string $variant = 'no_listen';

  public function test_without_notify_the_poll_relays_the_fact_within_the_interval(): void {
    $this->startWorker();
    $this->primeWorker(); // the worker is now somewhere in its 3 s sleep

    self::assertSame(
      0,
      (int) $this->db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND query ILIKE 'LISTEN %'"),
      'nobody LISTENs in this variant'
    );

    $eventId = $this->commitFact('w-poll');
    $seconds = $this->secondsUntilAccepted($eventId, self::POLL_SECONDS + 2.0);

    self::assertNotNull($seconds, 'the poll relayed the fact within one interval: ' . $this->workerErrors());
    self::assertLessThan(self::POLL_SECONDS + 1.0, $seconds);
  }
}
