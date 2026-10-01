<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Lock\IProcessLock;

/**
 * One worker of a host (wave 3, CR-W3CP-1): its own connection / lock
 * session, its own ProcessRunner and delivery runner, over the SAME
 * database as every other worker of the fixture (shared process store,
 * intents, outbox and delivery ledger).
 *
 * Worker 1 is the fixture's own connection: its runner is the one whose
 * resume and ignition subscribers sit in HostFixture::subscriptions(), and
 * its deliverFact() is HostFixture::deliver(). Worker 2 (and later) is what
 * a second php-fpm child, a second Messenger consumer or a second cron run
 * would be: a different connection, so the process lock excludes it.
 *
 * A worker other than 1 carries only the subscribers ProcessHost::wireProcesses()
 * declared (ignitions and resumes), not the stub listeners a scenario added
 * to HostFixture::subscriptions().
 */
interface ProcessWorker {

  /** This worker's runner, built on this worker's ports and wired per ProcessHost::wireProcesses(). */
  public function processRunner(): ProcessRunner;

  /** This worker's re-entrant process lock (its own lock session). */
  public function processLock(): IProcessLock;

  /** Run this worker's delivery runner once for one wrapped fact (shared ledger). */
  public function deliverFact(string $eventClass, array $wrapped): DeliveryOutcome;

  /**
   * One Drain::runOnce() on this worker: the relay step, the host's
   * delivery stage (if any), the due wakeups with this worker's runner as
   * the wake handler (register 3.6, W3C-R6), then the stranded scan.
   */
  public function drainOnce(int $maxItems = 200): DrainReport;
}
