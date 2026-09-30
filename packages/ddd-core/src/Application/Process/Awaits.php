<?php

namespace TangibleDDD\Application\Process;

use Attribute;

/**
 * Boot-time declaration of an event a process suspends on. The runtime await
 * (the mechanism in Result) owns the semantics; this is its static shadow —
 * every request must register the wake-up hook BEFORE the event fires, and
 * hooks can only be laid from static knowledge (PHP request amnesia).
 *
 * #[Awaits(SomeIntegrationEvent::class)]
 * final class MyProcess extends LongProcess { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Awaits {
  public function __construct(
    /** @var class-string<\TangibleDDD\Domain\Events\IIntegrationEvent> */
    public readonly string $event_class
  ) {}
}
