<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\TestCase;

/**
 * Domain/Shared/assert.php is a Composer `files` entry from wave 2 on
 * (register 1.1, 1.5) AND is still required by the winner's loader. On a WP
 * site with several vendored copies, Composer loads one copy's file and the
 * winning loader require_once's its own copy at a different path. A second
 * definition of TangibleDDD\Domain\Shared\assert_type() must be a no-op, not
 * a "Cannot redeclare" fatal.
 */
final class AssertFileAutoloadTest extends TestCase {

  private const FILE = 'packages/ddd-core/src/Domain/Shared/assert.php';

  public function test_composer_autoload_defines_assert_type(): void {
    $this->assertTrue(function_exists('TangibleDDD\\Domain\\Shared\\assert_type'));
  }

  public function test_a_second_copy_at_another_path_does_not_redeclare(): void {
    $root = dirname(__DIR__, 4);
    $copy = sys_get_temp_dir() . '/ddd-assert-copy-' . bin2hex(random_bytes(4)) . '.php';
    copy($root . '/' . self::FILE, $copy);

    try {
      $script = sprintf(
        'require %s; require %s; require %s; echo "ok";',
        var_export($root . '/vendor/autoload.php', true),
        var_export($root . '/' . self::FILE, true),
        var_export($copy, true),
      );
      exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    } finally {
      @unlink($copy);
    }

    $this->assertSame(0, $code, implode("\n", $out));
    $this->assertSame('ok', trim(implode("\n", $out)));
  }
}
