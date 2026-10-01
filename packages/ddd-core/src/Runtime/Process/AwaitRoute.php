<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * One index row of a suspended process's await (D3, register 3.8, 3.11):
 * the fact class it waits for and the await key ('' = unkeyed).
 * LongProcess::await_routes() lists them; a store that indexes awaits per
 * route (sf `process_waits`) writes one row per route, and
 * IProcessStore::findWaitingFor($class, $key) matches them. A store that
 * indexes only the `waiting_for` column stays correct, because the runner
 * filters every candidate through IAwaitMechanism::accepts().
 *
 * Key rule: a route carries a non-empty key only when the mechanism matches
 * the fact by IAwaitKeyed::await_key(), so the key the fact reports is the
 * key the route stores.
 */
final class AwaitRoute {

  public function __construct(
    /** @var class-string<IIntegrationEvent> */
    public readonly string $eventClass,
    public readonly string $awaitKey = '',
  ) {}

  /** The await key a fact reports (IAwaitKeyed), null when it reports none. */
  public static function keyOf(IIntegrationEvent $event): ?string {
    if (!$event instanceof IAwaitKeyed) {
      return null;
    }
    $key = $event->await_key();
    return $key === null || $key === '' ? null : $key;
  }
}
