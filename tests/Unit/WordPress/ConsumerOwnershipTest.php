<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\Acme\Domain\Widget;
use TangibleDDD\Tests\Fakes\Acme\Infra\Config as AcmeConfig;
use TangibleDDD\Tests\Fakes\Acme\Sub\Domain\Gadget;
use TangibleDDD\Tests\Fakes\Acme\Sub\Infra\Config as AcmeSubConfig;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\ConsumerRegistry;
use TangibleDDD\WordPress\NoConsumerOwnsClass;

use function TangibleDDD\WordPress\boot;
use function TangibleDDD\WordPress\consumers;

// Procedural ddd-wordpress files; load directly (mirrors BootTest).
if (!function_exists('TangibleDDD\\WordPress\\boot')) {
  require_once __DIR__ . '/../../../ddd-wordpress/hooks.php';
  require_once __DIR__ . '/../../../ddd-wordpress/tables.php';
  require_once __DIR__ . '/../../../ddd-wordpress/migrations.php';
  require_once __DIR__ . '/../../../ddd-wordpress/ConsumerHandle.php';
  require_once __DIR__ . '/../../../ddd-wordpress/ConsumerRegistry.php';
}
if (!class_exists('TangibleDDD\\WordPress\\NoConsumerOwnsClass')) {
  require_once __DIR__ . '/../../../ddd-wordpress/NoConsumerOwnsClass.php';
}

/**
 * Consumer identity by namespace, not by stamped subclass.
 *
 * owner_of(class) answers "which registered consumer owns this class?" by
 * longest namespace-root match — the resolution that lets framework base
 * classes (Command, DomainEvent, …) serve any consumer without a copied
 * per-consumer base carrying prefix()/container() in code.
 *
 * The root is derived from the config class's own namespace (the scaffolder
 * stamps it at <root>\Infra\Config, so a trailing \Infra segment is
 * stripped), overridable explicitly at boot().
 */
class ConsumerOwnershipTest extends TestCase {

  protected function setUp(): void {
    global $_test_actions, $_test_filters;
    $_test_actions = [];
    $_test_filters = [];
    $GLOBALS['wpdb'] = new \wpdb();
    ConsumerRegistry::reset();
  }

  private static function getter(): callable {
    return static fn () => new \stdClass();
  }

  public function test_namespace_root_is_derived_from_the_config_class_stripping_infra(): void {
    $handle = ConsumerRegistry::add(new AcmeConfig(), self::getter());

    $this->assertSame('TangibleDDD\\Tests\\Fakes\\Acme', $handle->namespace_root());
  }

  public function test_owner_of_resolves_a_class_to_the_consumer_whose_root_contains_it(): void {
    ConsumerRegistry::add(new AcmeConfig(), self::getter());

    $this->assertSame('acme', ConsumerRegistry::owner_of(Widget::class)->prefix());
  }

  public function test_owner_of_picks_the_longest_matching_root(): void {
    ConsumerRegistry::add(new AcmeConfig(), self::getter());
    ConsumerRegistry::add(new AcmeSubConfig(), self::getter());

    $this->assertSame('acme_sub', ConsumerRegistry::owner_of(Gadget::class)->prefix());
    $this->assertSame('acme', ConsumerRegistry::owner_of(Widget::class)->prefix());
  }

  public function test_owner_of_matches_whole_segments_only(): void {
    ConsumerRegistry::add(new FakeDDDConfig(), self::getter(), null, 'Foo\\Bar');

    $this->expectException(NoConsumerOwnsClass::class);
    ConsumerRegistry::owner_of('Foo\\Barbecue\\Thing');
  }

  public function test_owner_of_throws_when_no_consumer_owns_the_class(): void {
    ConsumerRegistry::add(new AcmeConfig(), self::getter());

    $this->expectException(NoConsumerOwnsClass::class);
    ConsumerRegistry::owner_of(\stdClass::class);
  }

  public function test_boot_accepts_an_explicit_root_that_survives_the_deferred_reregistration(): void {
    boot(new FakeDDDConfig(), self::getter(), 'Nice Label', 'Custom\\Root');

    do_action('init'); // register_hooks re-announces; must not clobber

    $handle = consumers()['test'];
    $this->assertSame('Custom\\Root', $handle->namespace_root());
    $this->assertSame('Nice Label', $handle->label(), 'label must survive re-registration too');
  }
}
