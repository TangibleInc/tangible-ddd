<?php

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Process\AwaitRoute;

/**
 * 1-of-1 await: suspend until the first event of $event_class whose public
 * properties strictly match $match_criteria. resume_argument() is the event
 * itself, so 2-param steps keep their existing signature.
 *
 * Keyed form (D3): with $await_key set, the event must also report that key
 * through IAwaitKeyed::await_key(), and the await is indexed under it
 * (route ($event_class, $await_key)). Key it on a ref the process minted
 * (LongProcess::step_ref()) and dispatch the command that leads to the
 * answer from the same step: the await commits before the command runs.
 *
 * Optional alarm (wave 4): $timeout_seconds > 0 schedules a durable Timeout
 * intent; $on_timeout says what it does (AwaitAll::TIMEOUT_FAIL compensates,
 * TIMEOUT_PROCEED resumes with a null event).
 *
 * Any awaited event class must be registered for wake-up — declare it with
 * #[Awaits(EventClass::class)] on the process class.
 */
final class AwaitEvent implements IAwaitMechanism, IRoutedAwait {

  /** @var class-string<IIntegrationEvent> */
  public readonly string $event_class;

  public function __construct(
    string $event_class,
    /** Criteria to match against event properties */
    public readonly array $match_criteria = [],
    /** D3 key the event must report through IAwaitKeyed (null = unkeyed) */
    public readonly ?string $await_key = null,
    public readonly int $timeout_seconds = 0,
    public readonly string $on_timeout = AwaitAll::TIMEOUT_FAIL,
  ) {
    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new InvalidArgumentException(
        "AwaitEvent expects an IIntegrationEvent class, got: $event_class"
      );
    }
    if ($await_key === '') {
      throw new InvalidArgumentException('AwaitEvent await_key must be null or non-empty');
    }
    if ($timeout_seconds < 0) {
      throw new InvalidArgumentException('AwaitEvent timeout_seconds must be >= 0');
    }
    if (!in_array($on_timeout, [AwaitAll::TIMEOUT_FAIL, AwaitAll::TIMEOUT_PROCEED], true)) {
      throw new InvalidArgumentException("Unknown on_timeout policy: $on_timeout");
    }
    $this->event_class = $event_class;
  }

  /** D3 keyed await on a ref the process minted. */
  public static function keyed(
    string $event_class,
    string $await_key,
    array $match_criteria = [],
    int $timeout_seconds = 0,
    string $on_timeout = AwaitAll::TIMEOUT_FAIL,
  ): self {
    return new self($event_class, $match_criteria, $await_key, $timeout_seconds, $on_timeout);
  }

  public function event_class(): string { return $this->event_class; }

  public function accepts(IIntegrationEvent $event): bool {
    if (!$event instanceof $this->event_class) {
      return false;
    }
    if ($this->await_key !== null && AwaitRoute::key_of($event) !== $this->await_key) {
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
  public function timeout_seconds(): int { return $this->timeout_seconds; }
  public function on_timeout(): string { return $this->on_timeout; }

  public function routes(): array {
    return [new AwaitRoute($this->event_class, $this->await_key ?? '')];
  }

  public function to_array(): array {
    $data = ['event_class' => $this->event_class, 'match_criteria' => $this->match_criteria];
    // The 0.6 shape stays exactly the 0.6 shape for an unkeyed, alarm-free await.
    if ($this->await_key !== null) {
      $data['await_key'] = $this->await_key;
    }
    if ($this->timeout_seconds > 0) {
      $data['timeout_seconds'] = $this->timeout_seconds;
      $data['on_timeout'] = $this->on_timeout;
    }
    return $data;
  }

  public static function from_array(array $data): static {
    return new static(
      $data['event_class'],
      $data['match_criteria'] ?? [],
      isset($data['await_key']) && $data['await_key'] !== '' ? (string) $data['await_key'] : null,
      (int) ($data['timeout_seconds'] ?? 0),
      $data['on_timeout'] ?? AwaitAll::TIMEOUT_FAIL,
    );
  }
}
