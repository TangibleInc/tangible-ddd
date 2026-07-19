<?php

namespace TangibleDDD\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Shared\Uuid;

/**
 * One UUID mint (0.3): RFC 4122 v4, CSPRNG (random_int), NO fallback —
 * random_int throws only when the OS has no entropy source, and silently
 * degrading uniqueness on a catastrophically broken box is worse than
 * crashing loudly. Replaces three per-site copies (whose fallbacks fell
 * to mt_rand/time — the actually-kooky part).
 */
class UuidTest extends TestCase {

  public function test_v4_shape_version_and_variant(): void {
    for ($i = 0; $i < 50; $i++) {
      $uuid = Uuid::v4();

      $this->assertMatchesRegularExpression(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        $uuid,
      );
    }
  }

  public function test_mints_are_unique(): void {
    $seen = [];
    for ($i = 0; $i < 1000; $i++) {
      $seen[Uuid::v4()] = true;
    }

    $this->assertCount(1000, $seen);
  }
}
