<?php

namespace TangibleDDD\Application\Process;

use Attribute;

/**
 * Boot-time declaration of an integration event that IGNITES this process.
 *
 * The reactive door: at drain time the framework hydrates the event, asks
 * the process class `from_event($event): ?static` (null = not my business —
 * the policy filter), dedups on the event's journey id (replay-safe), and
 * starts the returned instance right there in the flat drain context.
 *
 * The class must expose a static from_event() accepting the declared event.
 * Sibling of #[Awaits] (which wakes an already-suspended process); a class
 * may carry both — including for the same event, in which case ignition
 * consumes its triggering instance and the await sees only later ones.
 *
 * #[StartsOn(SomeIntegrationEvent::class)]
 * final class MyProcess extends LongProcess { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class StartsOn {
  public function __construct(
    /** @var class-string<\TangibleDDD\Domain\Events\IIntegrationEvent> */
    public readonly string $event_class
  ) {}
}
