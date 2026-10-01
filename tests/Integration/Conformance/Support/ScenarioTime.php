<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Runtime\IClock;

/**
 * The wall clock the transitional WordPress adapters read, bound to the
 * conformance host's IClock for the length of one scenario.
 *
 * The wave-2 wp adapters (OutboxRepository underneath WpdbOutboxStore,
 * WpdbOutboxAdministration) still call time() / gmdate() instead of an
 * IClock; the port contract says "the IClock every port of this host
 * reads" (HostFixture::clock()). clock-functions.php defines time() and
 * gmdate() in exactly those namespaces and routes them here, the technique
 * Symfony's ClockMock uses. With no clock installed they return the real
 * time, so nothing changes outside a scenario.
 *
 * Wave 3 replaces this with adapters that take the IClock (change request
 * WPC-3 in docs/extraction/wave2-wp-conformance-change-requests.md).
 */
final class ScenarioTime {

  private static ?IClock $clock = null;

  public static function install(IClock $clock): void {
    self::$clock = $clock;
  }

  public static function uninstall(): void {
    self::$clock = null;
  }

  public static function installed(): bool {
    return self::$clock !== null;
  }

  public static function now(): int {
    return self::$clock?->now()->getTimestamp() ?? \time();
  }
}
