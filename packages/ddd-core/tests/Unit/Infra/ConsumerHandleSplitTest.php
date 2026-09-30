<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Infra;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\Consumers\NotAWordPressConsumer;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Testing\StaticConsumerIdentity;

/**
 * The ConsumerHandle split and IDDDConfig's new parent (register 1.4, 3.1,
 * ruling #56): the registry stores the portable IConsumerIdentity; config()
 * still hands WordPress call sites their IDDDConfig and refuses, by name, a
 * consumer that registered only an identity.
 */
final class ConsumerHandleSplitTest extends TestCase {

  protected function setUp(): void {
    ConsumerRegistry::reset();
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  private function wpConfig(string $prefix = 'acme'): IDDDConfig {
    $config = $this->createStub(IDDDConfig::class);
    $config->method('prefix')->willReturn($prefix);
    $config->method('version')->willReturn('1.2.3');
    return $config;
  }

  public function test_idddconfig_extends_the_portable_identity(): void {
    $this->assertTrue(is_subclass_of(IDDDConfig::class, IConsumerIdentity::class));
  }

  public function test_a_handle_over_a_bare_identity_serves_the_portable_surface(): void {
    $identity = new StaticConsumerIdentity('txp', '0.1.0');
    $container = new \stdClass();
    $handle = new ConsumerHandle($identity, static fn () => $container, null, 'Txp');

    $this->assertSame($identity, $handle->identity());
    $this->assertSame('txp', $handle->prefix());
    $this->assertSame('txp', $handle->label());
    $this->assertSame('0.1.0', $handle->version());
    $this->assertSame('Txp', $handle->namespace_root());
    $this->assertSame($container, $handle->container());
  }

  public function test_config_refuses_an_identity_that_is_not_an_idddconfig(): void {
    $handle = new ConsumerHandle(new StaticConsumerIdentity('txp'), static fn () => null);

    $this->expectException(NotAWordPressConsumer::class);
    $this->expectExceptionMessageMatches('/txp/');
    $handle->config();
  }

  public function test_config_returns_the_registered_idddconfig(): void {
    $config = $this->wpConfig();
    $handle = new ConsumerHandle($config, static fn () => null);

    $this->assertSame($config, $handle->config());
    $this->assertSame($config, $handle->identity());
  }

  public function test_matches_registration_compares_identities(): void {
    $identity = new StaticConsumerIdentity('txp');
    $getter = static fn () => null;
    $handle = new ConsumerHandle($identity, $getter);

    $this->assertTrue($handle->matches_registration($identity, $getter));
    $this->assertFalse($handle->matches_registration(new StaticConsumerIdentity('txp'), $getter));
  }

  public function test_the_registry_accepts_an_identity_and_routes_by_namespace(): void {
    $handle = ConsumerRegistry::add(new StaticConsumerIdentity('txp'), static fn () => null, null, 'Txp');

    $this->assertSame($handle, ConsumerRegistry::owner_of('Txp\\Tenancy\\CreateTenant'));
    $this->assertSame('txp', ConsumerRegistry::consumer('txp')->identity()->prefix());
  }

  public function test_config_for_keeps_its_idddconfig_contract(): void {
    $config = $this->wpConfig('acme');
    ConsumerRegistry::add($config, static fn () => null);
    ConsumerRegistry::add(new StaticConsumerIdentity('txp'), static fn () => null, null, 'Txp');

    $this->assertSame($config, ConsumerRegistry::config_for('acme'));

    $this->expectException(NotAWordPressConsumer::class);
    ConsumerRegistry::config_for('txp');
  }

  public function test_a_module_below_an_identity_only_host_shares_its_identity(): void {
    $identity = new StaticConsumerIdentity('txp');
    ConsumerRegistry::add($identity, static fn () => null, null, 'Txp');

    $module = ConsumerRegistry::add_module('txp', 'Txp\\Billing', static fn () => null);

    $this->assertSame($identity, $module->identity());
    $this->assertSame($module, ConsumerRegistry::owner_of('Txp\\Billing\\Charge'));
  }
}
