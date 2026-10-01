<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * A durable process alarm (D7): suspend until an instant, then resume the
 * next step (its second parameter receives null). No fact wakes it; the
 * process waits for nothing in the `waiting_for` column.
 *
 * - AwaitAlarm::at($instant): due exactly at $instant, kept as absolute UTC.
 * - AwaitAlarm::after($seconds): due $seconds after the suspension. The
 *   runner fixes that instant once, when the step suspends, and stores it
 *   (LongProcess::await_deadline()); nothing re-delays it.
 *
 * The alarm is a Timeout intent row (register 3.6, 5.3): it survives worker
 * restarts and transport loss, fires once (the wake is stale-safe), and has
 * no upper bound (24 h, days, weeks).
 */
final class AwaitAlarm implements IAwaitMechanism, IHasDeadline {

  private function __construct(
    /** ISO 8601 UTC, for an absolute alarm */
    public readonly ?string $due_at,
    /** seconds after the suspension, for a relative alarm */
    public readonly int $seconds,
  ) {
    if ($due_at === null && $seconds <= 0) {
      throw new InvalidArgumentException('AwaitAlarm needs an instant or a positive number of seconds');
    }
  }

  public static function at(\DateTimeImmutable $instant): self {
    return new self($instant->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM), 0);
  }

  public static function after(int $seconds): self {
    return new self(null, $seconds);
  }

  /** '' : a timer waits for no fact (the runner skips the subscription check and the index). */
  public function event_class(): string { return ''; }

  public function accepts(IIntegrationEvent $event): bool { return false; }
  public function accumulate(IIntegrationEvent $event): static { return $this; }
  public function is_satisfied(): bool { return false; }
  public function resume_argument(?IIntegrationEvent $last_event): mixed { return null; }

  /** > 0 so every host sees an alarm; the deadline wins when set. */
  public function timeout_seconds(): int { return $this->due_at === null ? $this->seconds : 1; }

  public function on_timeout(): string { return AwaitAll::TIMEOUT_PROCEED; }

  public function deadline(): ?\DateTimeImmutable {
    return $this->due_at === null ? null : new \DateTimeImmutable($this->due_at, new \DateTimeZone('UTC'));
  }

  public function to_array(): array {
    return ['due_at' => $this->due_at, 'seconds' => $this->seconds];
  }

  public static function from_array(array $data): static {
    return new static(isset($data['due_at']) ? (string) $data['due_at'] : null, (int) ($data['seconds'] ?? 0));
  }
}
