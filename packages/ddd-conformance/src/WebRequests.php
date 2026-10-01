<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * Optional HostFixture seam for `process.start-from-web` (sf only, register
 * section 4 and 5.2; CR-W3CP-5). A fixture implementing it also implements
 * ProcessHost.
 */
interface WebRequests {

  /**
   * Run $fn as a web request: on the host's POOLED web connection (sf: the
   * DSN behind PgBouncer / a `-pooler` host) with the host's default start
   * mode (sf: StartMode::Deferred). The command bus HostFixture::command_bus()
   * builds and worker(1)'s runner are the request's.
   */
  public function in_web_request(callable $fn): mixed;

  /**
   * Boot the host with the in-band start opt-in (`ddd.process.inband_start:
   * true`) on a pooled DSN, and return what refused the boot, or null if it
   * booted.
   */
  public function boot_inband_pooled(): ?\Throwable;
}
