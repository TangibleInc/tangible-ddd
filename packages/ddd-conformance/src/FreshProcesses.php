<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Optional HostFixture seam for the multi-process ids (register section 4:
 * `relay.fresh-process-pickup`, `relay.crash-after-commit`,
 * `process.crash-mid-step`, `process.fresh-process-resume`; CR-W3CP-4).
 * A fixture implementing it also implements ProcessHost.
 *
 * Each method runs ONE fresh php process (pdo: a separate `php` script; wp:
 * a separate WP-CLI / loopback request; sf: a separate `bin/console`
 * process) against the fixture's per-test schema. Nothing is shared with
 * the test process except the database and the clock (EnvOffsetClock,
 * `DDD_CLOCK_OFFSET`, follows HostFixture::advance_clock()). The fresh
 * process boots like production does, plus Support\FreshProcessBoot::boot()
 * (the conformance subscribers, process wiring and journal binding), so its
 * effects are visible to the test as scenario rows.
 *
 * "Killed" means a hard exit at the stated point: no `finally`, no
 * shutdown function, no destructor that could still relay or save
 * (posix_kill(getmypid(), SIGKILL), or exit inside a shutdown-free child).
 */
interface FreshProcesses {

  /**
   * Publish $fact through the host command bus (one committed command) in a
   * fresh process, then exit; with $killAfterCommit the process is killed
   * right after the COMMIT, before any relay. Returns the event id.
   */
  public function publish_fresh(DomainEvent&IIntegrationEvent $fact, bool $killAfterCommit): string;

  /** One worker pass in a fresh process: Drain::run_once() plus the host's delivery of what it relayed. */
  public function drain_fresh(): FreshRun;

  /** Deliver one wrapped fact with the host delivery runner in a fresh process. */
  public function deliver_fresh(string $eventClass, array $wrapped): FreshRun;

  /**
   * Start $process (in-band: the first step runs in this fresh process) and
   * run it; with $dieAfterCommand the process is killed right after the
   * step command with that label committed (Fixtures\Process\StepCommand),
   * before the step's checkpoint is saved.
   */
  public function start_fresh(LongProcess $process, ?string $dieAfterCommand = null): FreshRun;
}
