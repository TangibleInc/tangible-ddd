<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wave 2 moved ddd-wordpress/ to packages/ddd-wp/wordpress/ (register 1.1).
 * Two callers still name the legacy paths:
 *
 *  - every legacy loader's self-consume hook requires
 *    `<winner>/ddd-wordpress/self/index.php` (report B5; a missing file there
 *    is an uncatchable fatal at plugins_loaded:20);
 *  - the winner's procedural list in tangible-ddd.php, which skips a missing
 *    file SILENTLY (file_exists guard), so a stale entry would drop hooks.php
 *    and friends without an error.
 *
 * Each legacy path is a one-line forwarding shim to the moved file.
 */
final class LegacyPathShimsTest extends TestCase {

  /** @return array<string, array{string}> */
  public static function loader_entries(): array {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/tangible-ddd.php');
    preg_match('/\$procedural\s*=\s*\[(.*?)\];/s', $source, $m);
    preg_match_all("/'([^']+\\.php)'/", $m[1] ?? '', $entries);

    $out = [];
    foreach ($entries[1] as $rel) {
      $out[$rel] = [$rel];
    }
    return $out;
  }

  /** @return array<string, array{string}> */
  public static function shims(): array {
    $out = ['ddd-wordpress/self/index.php' => ['ddd-wordpress/self/index.php']];
    foreach (array_keys(self::loader_entries()) as $rel) {
      if (str_starts_with($rel, 'ddd-wordpress/')) {
        $out[$rel] = [$rel];
      }
    }
    return $out;
  }

  public function test_the_loader_lists_procedural_files(): void {
    $this->assertGreaterThanOrEqual(10, count(self::loader_entries()));
  }

  #[DataProvider('loader_entries')]
  public function test_every_procedural_entry_of_the_loader_exists(string $rel): void {
    $this->assertFileExists(dirname(__DIR__, 3) . '/' . $rel, "the loader would skip {$rel} silently");
  }

  #[DataProvider('shims')]
  public function test_the_legacy_path_forwards_to_the_moved_file(string $rel): void {
    $root = dirname(__DIR__, 3);
    $target = 'packages/ddd-wp/wordpress/' . substr($rel, strlen('ddd-wordpress/'));

    $this->assertFileExists($root . '/' . $target);
    $this->assertFileExists($root . '/' . $rel);

    $shim = (string) file_get_contents($root . '/' . $rel);
    $depth = substr_count($rel, '/');
    $this->assertStringContainsString(
      sprintf("require_once dirname(__DIR__, %d) . '/%s';", $depth, $target),
      $shim,
      "{$rel} must forward to {$target}"
    );
    $this->assertDoesNotMatchRegularExpression('/\bfunction\s+\w+\s*\(|\bclass\s+\w+/', $shim, 'a shim defines nothing itself');
  }
}
