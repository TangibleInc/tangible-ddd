<?php

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * 1-of-1 await: suspend until the first event of $event_class whose public
 * properties strictly match $match_criteria. resume_argument() is the event
 * itself, so 2-param steps keep their existing signature.
 *
 * Any awaited event class must be registered for wake-up — declare it with
 * #[Awaits(EventClass::class)] on the process class.
 */
final class AwaitEvent implements IAwaitMechanism {

  /** @var class-string<IIntegrationEvent> */
  public readonly string $event_class;

  public function __construct(
    string $event_class,
    /** Criteria to match against event properties */
    public readonly array $match_criteria = [],
  ) {
    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new InvalidArgumentException(
        "AwaitEvent expects an IIntegrationEvent class, got: $event_class"
      );
    }
    $this->event_class = $event_class;
  }

  public function event_class(): string { return $this->event_class; }

  public function accepts(IIntegrationEvent $event): bool {
    if (!$event instanceof $this->event_class) {
      return false;
    }
    foreach ($this->match_criteria as $key => $expected) {
      if (!property_exists($event, $key) || $event->$key !== $expected) {
        return false;
      }
    }
    return true;
  }

  public function accumulate(IIntegrationEvent $event): static { return $this; }
  public function is_satisfied(): bool { return true; }
  public function resume_argument(?IIntegrationEvent $last_event): mixed { return $last_event; }
  public function timeout_seconds(): int { return 0; }
  // NOTE: literal 'fail' for this task — AwaitAll::TIMEOUT_FAIL arrives in Task 8.
  public function on_timeout(): string { return 'fail'; }

  public function to_array(): array {
    return ['event_class' => $this->event_class, 'match_criteria' => $this->match_criteria];
  }

  public static function from_array(array $data): static {
    return new static($data['event_class'], $data['match_criteria'] ?? []);
  }
}
