<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/** An await on a marker interface (D2-style), for find_waiting_for's is_a match. */
final class MarkerAwait implements IAwaitMechanism {

  public function event_class(): string { return PingMarker::class; }

  public function accepts(IIntegrationEvent $event): bool { return $event instanceof PingMarker; }

  public function accumulate(IIntegrationEvent $event): static { return $this; }

  public function is_satisfied(): bool { return true; }

  public function resume_argument(?IIntegrationEvent $last_event): mixed { return $last_event; }

  public function timeout_seconds(): int { return 0; }

  public function on_timeout(): string { return 'fail'; }

  public function to_array(): array { return []; }

  public static function from_array(array $data): static { return new static(); }
}
