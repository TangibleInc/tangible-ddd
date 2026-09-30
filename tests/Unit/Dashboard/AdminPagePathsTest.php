<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * AdminPage builds the dashboard's asset URLs and template path from the
 * framework root (the winner's plugin path). After the wave-2 move those
 * files live under packages/ddd-wp/wordpress/Admin/Dashboard/; a stale
 * relative path 404s the assets and fatals render().
 */
final class AdminPagePathsTest extends TestCase {

  private static function source(): string {
    return (string) file_get_contents(dirname(__DIR__, 3) . '/packages/ddd-wp/wordpress/Admin/Dashboard/AdminPage.php');
  }

  public function test_the_asset_base_is_relative_to_the_framework_root_and_exists(): void {
    $this->assertSame(1, preg_match("/\\\$base = '([^']+)';/", self::source(), $m));
    $root = dirname(__DIR__, 3);
    foreach (['dashboard.css', 'dashboard.js', 'trace-island.js'] as $asset) {
      $this->assertFileExists($root . '/' . $m[1] . $asset);
    }
  }

  public function test_the_template_path_is_relative_to_the_framework_root_and_exists(): void {
    $this->assertSame(1, preg_match("/require \\\$this->frameworkPath \\. '([^']+)';/", self::source(), $m));
    $this->assertFileExists(dirname(__DIR__, 3) . $m[1]);
  }
}
