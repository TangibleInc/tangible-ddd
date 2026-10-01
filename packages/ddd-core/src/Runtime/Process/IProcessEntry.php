<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * The two fact-driven doors into the process runner that
 * SubscriptionRegistrar subscribes (ruling #80). ProcessRunner implements it
 * from wave 2 (CR-3, ratified).
 *
 * - ignite(): the #[StartsOn] path ONLY. Asks `$processClass::from_event()`
 *   (null = declined, return quietly), then IProcessStore::insert_ignited()
 *   with ignition_key = uuid5(event_id, process_class); AlreadyIgnited
 *   returns without running a step (bug 2, X7).
 * - resume(): wakes processes suspended on this fact (find_waiting_for), each
 *   under its process lock with a re-read.
 *
 * Error behaviour: lock and store errors propagate, so the delivery invoker
 * records a failed attempt and the fact is retried for this subscriber
 * only. ProcessRunner surfaces a lock failure as ProcessLockUnavailable (a
 * 0.6 LockingException whose previous is the port's LockNotAcquired).
 * An empty $eventId (an id-less legacy payload) means no dedup is possible:
 * ProcessRunner starts the process as 0.6 did.
 */
interface IProcessEntry {

  /** @param class-string<\TangibleDDD\Application\Process\LongProcess> $processClass */
  public function ignite(string $processClass, IIntegrationEvent $event, string $eventId): void;

  public function resume(IIntegrationEvent $event): void;
}
