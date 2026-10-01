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
 * - startRelayWorker(): the worker starts, makes its first (empty) relay
 *   pass and starts listening. Called before the scenario commits, so a
 *   later delivery can only come from a wakeup or a poll.
 * - relayUntilTransported($eventId, $timeout): continue the worker until
 *   $eventId was handed to the transport, or $timeout seconds of WALL time
 *   passed. Returns the wall seconds from the call to the hand-off, or null.
 * - wakeupArrives($timeout): whether a wakeup reaches the listening worker
 *   within $timeout wall seconds (it consumes pending wakeups; it relays
 *   nothing).
 * - suppressNextWakeup(): the next commit sends no wakeup (a lost NOTIFY),
 *   one-shot.
 * - relayPollIntervalSeconds(): the worker's poll interval with no wakeup.
 *   Keep it short in the fixture (a few seconds): the scenario waits it out.
 * - stopRelayWorker(): stop listening (also done by tearDown()).
 */
interface PostCommitWakeups {

  public function startRelayWorker(): void;

  public function relayUntilTransported(string $eventId, float $timeoutSeconds): ?float;

  public function wakeupArrives(float $timeoutSeconds): bool;

  public function suppressNextWakeup(): void;

  public function relayPollIntervalSeconds(): float;

  public function stopRelayWorker(): void;
}
