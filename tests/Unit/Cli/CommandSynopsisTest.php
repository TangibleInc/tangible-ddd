<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Every `## OPTIONS` synopsis line of the `wp ddd` command docblocks must be a
 * token WP-CLI's SynopsisParser accepts; one it rejects makes WP-CLI warn
 * "The `wp ddd <cmd>` command has an invalid synopsis part" on every run.
 *
 * The patterns are WP-CLI's own (SynopsisParser::classify_token: name
 * `[a-z-_0-9]+`, value `[a-zA-Z-_|,0-9]+`), checked against the source text so
 * the test needs neither WP-CLI nor WordPress.
 */
final class CommandSynopsisTest extends TestCase {

  private const NAME = '[a-z\-_0-9]+';
  private const VALUE = '[a-zA-Z\-_|,0-9]+';

  public function test_every_synopsis_token_is_one_wp_cli_accepts(): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/ddd-wp/wordpress/cli/class-ddd-command.php');
    preg_match_all('#/\*\*(.*?)\*/\s*public function (\w+)#s', $source, $docs, PREG_SET_ORDER);
    self::assertNotEmpty($docs);

    $checked = 0;
    $invalid = [];
    foreach ($docs as [, $doc, $method]) {
      if (!preg_match('/## OPTIONS(.*?)(?:## EXAMPLES|$)/s', $doc, $options)) {
        continue;
      }
      foreach (preg_split('/\R/', $options[1]) as $line) {
        $line = trim(ltrim(trim($line), '*'));
        if ($line === '' || !preg_match('/^\[?(--|<)/', $line)) {
          continue;
        }
        $checked++;
        if (!self::accepted($line)) {
          $invalid[] = "$method: $line";
        }
      }
    }

    self::assertGreaterThan(0, $checked);
    self::assertSame([], $invalid, 'synopsis parts WP-CLI rejects');
  }

  public function test_the_pattern_rejects_what_wp_cli_rejects(): void {
    self::assertFalse(self::accepted('[--abandon=<subscriber@event>]'));
    self::assertTrue(self::accepted('[--abandon=<pair>]'));
    self::assertTrue(self::accepted('<prefix>'));
    self::assertTrue(self::accepted('[--dry-run]'));
  }

  private static function accepted(string $token): bool {
    $token = preg_replace('/^\[(.*)\]$/', '$1', $token);
    $token = preg_replace('/\.\.\.$/', '', (string) $token);
    $name = self::NAME;
    $value = self::VALUE;
    return (bool) preg_match("/^<$value>$/", (string) $token)
      || (bool) preg_match("/^--(?:\\[no-\\])?$name(?:\\[?=<$value>\\]?)?$/", (string) $token);
  }
}
