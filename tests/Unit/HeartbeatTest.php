<?php

namespace TangibleDDD\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Basic heartbeat test to verify PHPUnit is working.
 */
class HeartbeatTest extends TestCase {

  public function test_phpunit_is_working(): void {
    $this->assertTrue( true );
  }

  public function test_autoloader_works(): void {
    // Verify we can load framework classes
    $this->assertTrue( class_exists( \TangibleDDD\Domain\Shared\ValueObject::class ) );
    $this->assertTrue( class_exists( \TangibleDDD\Domain\Shared\Aggregate::class ) );
  }
}
