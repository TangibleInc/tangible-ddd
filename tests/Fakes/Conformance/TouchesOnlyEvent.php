<?php

namespace TangibleDDD\Tests\Fakes\Conformance;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;
use TangibleDDD\Tests\Fakes\Acme\Domain\StateLicense;

#[Touches(Op::Updated, StateLicense::class, id: 'missing')]
final class TouchesOnlyEvent extends DomainEvent {

  public function __construct(public readonly int $other) {}

  protected static function prefix(): string {
    return 'test';
  }

  public function payload(): array {
    return ['other' => $this->other];
  }
}
