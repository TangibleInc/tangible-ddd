<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Testing\IntegrationConformance;

/**
 * The core form of IntegrationConformance (register 1.2, 1.4): the listener
 * scan targets IntegrationTranslator and never names the WordPress
 * IntegrationListener, so it runs with no WordPress symbol loaded.
 */
final class IntegrationConformanceCoreTest extends TestCase {

  private const FIXTURES = __DIR__ . '/../Fixtures/Conformance';

  public function test_the_core_form_does_not_name_the_wordpress_listener(): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Testing/IntegrationConformance.php');

    $this->assertStringNotContainsString('IntegrationListener', $source);
  }

  public function test_a_translator_with_an_object_dependency_is_flagged(): void {
    $violations = IntegrationConformance::listener_violations(self::FIXTURES);
    $classes = array_column($violations, 'class');

    $this->assertContains('TangibleDDD\\Core\\Tests\\Unit\\Fixtures\\Conformance\\FatTranslator', $classes);
    $this->assertNotContains('TangibleDDD\\Core\\Tests\\Unit\\Fixtures\\Conformance\\ThinTranslator', $classes);
    $this->assertNotContains('TangibleDDD\\Core\\Tests\\Unit\\Fixtures\\Conformance\\ConfiguredTranslator', $classes);
    $this->assertCount(1, $violations);
    $this->assertSame('repo', $violations[0]['param']);
  }
}
