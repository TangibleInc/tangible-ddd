<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\RecordBehaviour;

class FakeResolvedEvent extends DomainEvent {
  use RecordBehaviour;

  public function __construct(
    public readonly int $request_id,
    public readonly FakeOutcome $outcome,
    public readonly \DateTimeImmutable $resolved_at,
    public readonly array $extra = [],
  ) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}
