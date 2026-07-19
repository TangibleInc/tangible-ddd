<?php

namespace TangibleDDD\Tests\Fakes\Acme\Domain;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;

/** A fact declaring its writes — explicit id param + a second, convention-named touch. */
#[Touches(Op::Created, StateLicense::class, id: 'the_license')]
#[Touches(Op::Updated, Roster::class)]
class LicenseIssued extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration {
  use IntegrationBehaviour;

  public function __construct(
    public readonly int $the_license,
    public readonly int $roster_id,
  ) {}

  protected static function prefix(): string { return 'acme'; }

  public function payload(): array { return $this->integration_payload(); }
}
