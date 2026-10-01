<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * Optional seam for `wakeup.post-commit` (D14; wave 4, CR-W4C4-5; register
 * section 4, sf only: transactional NOTIFY). A separate interface, so
 * HostFixture is unchanged; a fixture without it has the scenario skipped
 * with the request id.
 *
 * The host's relay worker is the production one (sf `ddd:relay`: LISTEN on
 * a direct connection, wait for a notification or the poll interval, relay,
 * repeat), driven in steps on its own connection:
 *
 * - start_relay(): the worker starts, makes its first (empty) relay
 *   pass and starts listening. Called before the scenario commits, so a
 *   later delivery can only come from a wakeup or a poll.
 * - relay_until($eventId, $timeout): continue the worker until
 *   $eventId was handed to the transport, or $timeout seconds of WALL time
 *   passed. Returns the wall seconds from the call to the hand-off, or null.
 * - await_wakeup($timeout): whether a wakeup reaches the listening worker
 *   within $timeout wall seconds (it consumes pending wakeups; it relays
 *   nothing).
 * - drop_next_wakeup(): the next commit sends no wakeup (a lost NOTIFY),
 *   one-shot.
 * - poll_seconds(): the worker's poll interval with no wakeup.
 *   Keep it short in the fixture (a few seconds): the scenario waits it out.
 * - stop_relay(): stop listening (also done by tear_down()).
 */
interface PostCommitWakeups {

  public function start_relay(): void;

  public function relay_until(string $eventId, float $timeoutSeconds): ?float;

  public function await_wakeup(float $timeoutSeconds): bool;

  public function drop_next_wakeup(): void;

  public function poll_seconds(): float;

  public function stop_relay(): void;
}
