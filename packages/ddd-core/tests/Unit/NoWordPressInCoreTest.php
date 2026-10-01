<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard for register 1.2: portable core contains no WordPress symbol,
 * no `global $`, no TangibleDDD\WordPress reference and no loader.
 */
final class NoWordPressInCoreTest extends TestCase {

  /**
   * Includes the wave-2 acceptance grep set (apply_filters, as_enqueue,
   * update_option, wp_json_encode, get_current_user_id, $GLOBALS).
   */
  private const FORBIDDEN = '/wpdb|add_action|do_action|apply_filters|as_schedule|as_enqueue|get_option|update_option|is_multisite|wp_json_encode|get_current_user_id|\$GLOBALS|global \$|TangibleDDD\\\\WordPress|Tangible_DDD_Versions|ActionScheduler/';

  public function test_core_sources_name_no_wordpress_symbol(): void {
    $src = dirname(__DIR__, 2) . '/src';
    $offenders = [];

    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      foreach (file($file->getPathname()) as $n => $line) {
        if (preg_match(self::FORBIDDEN, $line)) {
          $offenders[] = substr($file->getPathname(), strlen($src) + 1) . ':' . ($n + 1) . ': ' . trim($line);
        }
      }
    }

    self::assertSame([], $offenders, "WordPress symbols in ddd-core:\n" . implode("\n", $offenders));
  }
}
