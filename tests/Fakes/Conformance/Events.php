<?php

/**
 * Conformance-scanner fixtures: one file, several small classes — the scanner
 * must find every class in a file, not just the one matching the filename.
 */

namespace TangibleDDD\Tests\Fakes\Conformance;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;
use TangibleDDD\Tests\Fakes\FakeOutcome;

/** Fully legal wire schema: scalars, nullable, enum, immutable date, array. */
class ConformantEvent extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(
    public readonly int $user_id,
    public readonly ?string $reason,
    public readonly FakeOutcome $outcome,
    public readonly \DateTimeImmutable $occurred_at,
    public readonly array $course_ids = [],
  ) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}

/** VIOLATION: entity ctor param — throws NonReversibleValue at first publish. */
class EntityLadenEvent extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly FakeEntity $entity) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}

/** VIOLATION: mutable DateTime — revive() returns DateTimeImmutable → TypeError. */
class MutableDateEvent extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly \DateTime $when) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}

/** VIOLATION: union type — revive() cannot coerce non-named types. */
class UnionTypedEvent extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly int|string $ref) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}

/** VIOLATION: schema-less param — the ctor IS the wire schema. */
class UntypedEvent extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  /** @var mixed */
  public $anything;

  public function __construct($anything) {
    $this->anything = $anything;
  }

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}

/** Abstract bases are exempt — they have no wire schema of their own. */
abstract class AbstractEventBase extends DomainEvent implements IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly FakeEntity $would_be_illegal_if_concrete) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}

/** Not an integration event at all — scanner must ignore it. */
class PlainValueHolder {
  public function __construct(public readonly FakeEntity $entity) {}
}
