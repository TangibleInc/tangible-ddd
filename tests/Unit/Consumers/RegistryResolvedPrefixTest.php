<?php

namespace TangibleDDD\Tests\Unit\Consumers;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\Consumers\NoConsumerOwnsClass;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\Acme\Domain\OrphanEvent;
use TangibleDDD\Tests\Fakes\Acme\Domain\WidgetShipped;

/**
 * Event::prefix() resolves through owner_of() (0.2.5c): a concrete event in
 * a consumer's namespace extends the FRAMEWORK base directly — no stamped
 * per-consumer DomainEvent/IntegrationEvent middle class. Late static
 * binding supplies the leaf; the leaf's namespace IS the consumer identity.
 *
 * Stamped bases that still override prefix() keep winning — the default is
 * additive, no lockstep.
 */
class RegistryResolvedPrefixTest extends TestCase {

  protected function setUp(): void {
    ConsumerRegistry::reset();
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  public function test_event_extending_the_framework_base_resolves_its_consumer_prefix(): void {
    ConsumerRegistry::add(
      new DDDConfig(prefix: 'acme', namespace_root: 'TangibleDDD\\Tests\\Fakes\\Acme', version: 't'),
      static fn () => new \stdClass(),
    );

    $this->assertSame('acme_domain_widget_shipped', WidgetShipped::action());
    $this->assertSame('acme_integration_widget_shipped', WidgetShipped::integration_action());
  }

  public function test_unowned_event_fails_loudly(): void {
    $this->expectException(NoConsumerOwnsClass::class);

    OrphanEvent::action();
  }
}
