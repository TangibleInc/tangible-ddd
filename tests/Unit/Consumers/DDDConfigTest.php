<?php

namespace TangibleDDD\Tests\Unit\Consumers;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\IDDDConfig;

/**
 * DDDConfig — the framework's concrete IDDDConfig (0.2.5c).
 *
 * The eight prefix-derived methods were identical in every consumer's
 * stamped Infra\Config: framework knowledge wearing a consumer costume.
 * Consumers now INSTANTIATE instead of implement:
 *
 *   boot(new DDDConfig(prefix: 'tgbl_cred', namespace_root: 'Tangible\\Cred',
 *        version: TCRED_VERSION), fn () => di());
 *
 * namespace_root is an explicit ctor arg — the identity stated plainly.
 * Hand-written IDDDConfig implementations stay legal (bespoke table naming).
 */
class DDDConfigTest extends TestCase {

  private function make(): DDDConfig {
    return new DDDConfig(prefix: 'tgbl_x', namespace_root: 'Acme\\X', version: '1.2.3');
  }

  public function test_implements_the_contract(): void {
    $this->assertInstanceOf(IDDDConfig::class, $this->make());
  }

  public function test_the_eight_derivations(): void {
    global $wpdb;
    $wpdb = new \wpdb();
    $wpdb->prefix = 'wp_';

    $c = $this->make();

    $this->assertSame('tgbl_x', $c->prefix());
    $this->assertSame('wp_tgbl_x_command_audit', $c->table('command_audit'));
    $this->assertSame('tgbl_x_boot', $c->hook('boot'));
    $this->assertSame('tgbl_x-outbox', $c->as_group('outbox'));
    $this->assertSame('tgbl_x_schema', $c->option('schema'));
    $this->assertSame('tgbl_x_domain_earning_issued', $c->domain_action('earning_issued'));
    $this->assertSame('tgbl_x_integration_earning_issued', $c->integration_action('earning_issued'));
    $this->assertSame('1.2.3', $c->version());
  }

  public function test_namespace_root_is_explicit_not_derived(): void {
    ConsumerRegistry::reset();

    $handle = ConsumerRegistry::add($this->make(), static fn () => new \stdClass());

    // Derivation would say "TangibleDDD\Infra" (this class's own namespace) — wrong
    // for a framework-shipped concrete. The ctor arg is authoritative.
    $this->assertSame('Acme\\X', $handle->namespace_root());

    ConsumerRegistry::reset();
  }
}
