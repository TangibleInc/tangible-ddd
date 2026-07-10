<?php

namespace TangibleDDD\Application\Process;

use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Strategy VO for process suspension. The framework counts arrivals
 * (structural); the coordinator judges outcomes (post-await step).
 *
 * Must serialize to plain scalars — snapshot-resume cannot hold closures.
 */
interface IAwaitMechanism {

  /** SQL prefilter — goes in the waiting_for column. */
  public function event_class(): string;

  /** Routing: is this event for THIS process? */
  public function accepts(IIntegrationEvent $event): bool;

  /** Record an arrival — immutable, returns new instance. */
  public function accumulate(IIntegrationEvent $event): static;

  /** Structural satisfaction — everything ARRIVED? Never judges success. */
  public function is_satisfied(): bool;

  /** What the post-await step receives as its 2nd parameter. */
  public function resume_argument(?IIntegrationEvent $last_event): mixed;

  /** Wall-clock seconds; 0 = no alarm. */
  public function timeout_seconds(): int;

  /** 'fail' | 'proceed' — only consulted when the alarm fires. */
  public function on_timeout(): string;

  /** Persistence codec: plain array of scalars. */
  public function to_array(): array;

  public static function from_array(array $data): static;
}
