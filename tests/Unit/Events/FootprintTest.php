<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\Footprint;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Tests\Fakes\Acme\Domain\LicenseIssued;
use TangibleDDD\Tests\Fakes\Acme\Infra\Config as AcmeConfig;

/**
 * The declared harvest (spec appendix 9): Footprint::of_event() projects an
 * event's #[Touches] into at-rest entries — canonical name resolved through
 * the registry (class refs in domain code, strings only at rest), id from
 * the named ctor param or the {canonical_name}_id convention. The harvest
 * NEVER throws: it is post-commit decoration (the JLV ruling) — bad
 * declarations are logged and skipped; the conformance scan is the hard gate.
 */
class FootprintTest extends TestCase {

  protected function setUp(): void {
    ConsumerRegistry::reset();
    ConsumerRegistry::add(new AcmeConfig(), static fn () => new \stdClass());
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  public function test_projects_touches_with_explicit_and_convention_ids(): void {
    $touches = Footprint::of_event(new LicenseIssued(the_license: 4021, roster_id: 7));

    $this->assertSame([
      ['aggregate' => 'acme.state_license', 'id' => '4021', 'op' => 'created'],
      ['aggregate' => 'acme.roster', 'id' => '7', 'op' => 'updated'],
    ], $touches);
  }

  public function test_undeclared_events_have_no_touches(): void {
    $bare = new class(1) extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration {
      use IntegrationBehaviour;
      public function __construct(public readonly int $x) {}
      protected static function prefix(): string { return 'acme'; }
      public function payload(): array { return $this->integration_payload(); }
    };

    $this->assertSame([], Footprint::of_event($bare));
  }

  public function test_harvest_never_throws_on_a_bad_declaration(): void {
    // #[Touches(stdClass)] — the ctor guard would throw at newInstance();
    // the harvest is post-commit decoration, so it logs and skips instead.
    $touches = Footprint::of_event(new BadlyTouchingEvent(9));

    $this->assertSame([], $touches);
  }

  public function test_missing_id_param_is_skipped_not_fatal(): void {
    $touches = Footprint::of_event(new IdlessTouchingEvent(3));

    $this->assertSame([], $touches, 'no resolvable subject id — skip the entry (conformance catches it in CI)');
  }
}

#[Touches(Op::Updated, \stdClass::class)]
class BadlyTouchingEvent extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration {
  use IntegrationBehaviour;
  public function __construct(public readonly int $x) {}
  protected static function prefix(): string { return 'acme'; }
  public function payload(): array { return $this->integration_payload(); }
}

#[Touches(Op::Deleted, \TangibleDDD\Tests\Fakes\Acme\Domain\StateLicense::class)]
class IdlessTouchingEvent extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration {
  use IntegrationBehaviour;
  // no 'state_license_id' param and no explicit id: → unresolvable subject
  public function __construct(public readonly int $x) {}
  protected static function prefix(): string { return 'acme'; }
  public function payload(): array { return $this->integration_payload(); }
}
