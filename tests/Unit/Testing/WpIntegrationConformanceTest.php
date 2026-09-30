<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Testing\IntegrationConformance;
use TangibleDDD\WordPress\Testing\WpIntegrationConformance;

/**
 * The IntegrationConformance split (register 1.4): the core form targets
 * IntegrationTranslator, the wp subclass keeps the IntegrationListener
 * constructor exemption. On WordPress listeners both give the 0.6 verdicts.
 */
final class WpIntegrationConformanceTest extends TestCase {

  private const FIXTURES = __DIR__ . '/../../Fakes/Conformance';

  public function test_the_wp_form_is_the_core_form(): void {
    $this->assertTrue(is_subclass_of(WpIntegrationConformance::class, IntegrationConformance::class));
  }

  public function test_the_wp_form_flags_only_the_fat_listener(): void {
    $classes = array_column(WpIntegrationConformance::listener_violations(self::FIXTURES), 'class');

    $this->assertSame(['TangibleDDD\\Tests\\Fakes\\Conformance\\FatListener'], $classes);
  }

  public function test_the_core_and_wp_forms_agree_on_wordpress_listeners(): void {
    $this->assertSame(
      IntegrationConformance::listener_violations(self::FIXTURES),
      WpIntegrationConformance::listener_violations(self::FIXTURES),
    );
  }

  public function test_the_wp_form_exempts_the_self_registering_constructor(): void {
    $bases = (new \ReflectionMethod(WpIntegrationConformance::class, 'listener_bases'))->invoke(null);

    $this->assertContains(\TangibleDDD\Application\EventHandlers\IntegrationListener::class, $bases);
    $this->assertContains(\TangibleDDD\Application\EventHandlers\IntegrationTranslator::class, $bases);
  }
}
