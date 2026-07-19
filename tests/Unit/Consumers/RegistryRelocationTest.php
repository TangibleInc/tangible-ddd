<?php

namespace TangibleDDD\Tests\Unit\Consumers;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\Consumers\NoConsumerOwnsClass;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

/**
 * The registry moves to ddd-src (0.2.5c step zero): Event::prefix() and
 * Command::container() are about to resolve through owner_of(), and Domain
 * must not depend on the WordPress layer. The registry was always pure PHP
 * (IDDDConfig + a callable); ddd-wordpress was a historical accident.
 *
 * Old names survive as autoloadable alias stubs (the IntegrationEnvelope
 * recipe) through 0.2.x; they die in 0.3.
 */
class RegistryRelocationTest extends TestCase {

  protected function setUp(): void {
    ConsumerRegistry::reset();
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  public function test_new_home_is_ddd_src(): void {
    $this->assertTrue(class_exists(ConsumerRegistry::class));
    $this->assertTrue(class_exists(\TangibleDDD\Infra\Consumers\ConsumerHandle::class));
    $this->assertTrue(class_exists(NoConsumerOwnsClass::class));

    $file = (new \ReflectionClass(ConsumerRegistry::class))->getFileName();
    $this->assertStringContainsString('/ddd-src/', $file, 'Domain-reachable layer, not ddd-wordpress');
  }

  public function test_old_names_alias_to_the_same_classes(): void {
    $this->assertSame(
      ConsumerRegistry::class,
      (new \ReflectionClass(\TangibleDDD\WordPress\ConsumerRegistry::class))->getName(),
    );
    $this->assertSame(
      \TangibleDDD\Infra\Consumers\ConsumerHandle::class,
      (new \ReflectionClass(\TangibleDDD\WordPress\ConsumerHandle::class))->getName(),
    );
    $this->assertSame(
      NoConsumerOwnsClass::class,
      (new \ReflectionClass(\TangibleDDD\WordPress\NoConsumerOwnsClass::class))->getName(),
    );
  }

  public function test_old_and_new_names_share_one_registry(): void {
    $handle = \TangibleDDD\WordPress\ConsumerRegistry::add(new FakeDDDConfig(), static fn () => new \stdClass());

    $this->assertSame($handle, ConsumerRegistry::all()['test'], 'aliased statics are the same storage');
    $this->assertSame('test', ConsumerRegistry::owner_of(FakeDDDConfig::class)->prefix());
  }
}
