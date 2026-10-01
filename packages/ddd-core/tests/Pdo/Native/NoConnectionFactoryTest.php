<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use PHPUnit\Framework\TestCase;

/**
 * Register 1.2 / operator constraint: Defaults/Pdo takes the host's own
 * connection. No `new PDO`, no DSN handling, no migrator, no loop or
 * daemon anywhere under src/Defaults/Pdo (checked on the PHP tokens, so
 * comments and strings do not count).
 */
final class NoConnectionFactoryTest extends TestCase {

  /** @return iterable<string, array{0: string}> */
  public static function sources(): iterable {
    $dir = dirname(__DIR__, 3) . '/src/Defaults/Pdo';
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
      if ($file->getExtension() === 'php') {
        yield substr($file->getPathname(), strlen($dir) + 1) => [$file->getPathname()];
      }
    }
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('sources')]
  public function test_no_pdo_construction_dsn_or_runtime_loop(string $path): void {
    $code = '';
    foreach (\PhpToken::tokenize((string) file_get_contents($path)) as $t) {
      if (!$t->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_WHITESPACE])) {
        $code .= $t->text . ' ';
      }
    }

    self::assertDoesNotMatchRegularExpression('/\bnew\s+\\\\?PDO\b/i', $code, 'no new PDO');
    self::assertDoesNotMatchRegularExpression('/\b(parse_url|getenv)\b/', $code, 'no DSN or credential lookup');
    self::assertDoesNotMatchRegularExpression('/\b(sleep|usleep|pcntl_\w+)\s*\(/', $code, 'no daemon loop or signal handling');
    self::assertDoesNotMatchRegularExpression('/\bwhile\s*\(\s*true\s*\)/i', $code, 'no infinite loop');
    self::assertStringNotContainsString('PDO::ATTR_', str_replace(['PDO::ATTR_ERRMODE', 'PDO::ATTR_DRIVER_NAME'], '', $code), 'reads only ERRMODE and the driver name, never sets attributes');
    self::assertDoesNotMatchRegularExpression('/->\s*setAttribute\s*\(/', $code, 'never reconfigures the host connection');
  }
}
