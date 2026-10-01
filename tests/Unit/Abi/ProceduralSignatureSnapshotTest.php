<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Abi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Unit\Abi\Support\ProceduralSignatures;

/**
 * ABI freeze, register B14 (section 8, wave 2 wp): the procedural API
 * consumers call (`TangibleDDD\WordPress\boot`, `install_tables`,
 * `with_lock`, `encrypt_secret`, `sql_placeholders`, ... and the
 * `DDD_SCHEMA_VERSION` constant cred's tests read) keeps every function of
 * every in-window tag, under the same FQN, with a compatible signature.
 *
 * - Legacy snapshots (fixtures/procedural/<tag>.json) were read from
 *   `git show <tag>:ddd-wordpress/**.php` by bin/generate-fixtures.php.
 * - The current side is read live from packages/ddd-wp/wordpress/ with the
 *   same reader, and each function is also checked to be really declared
 *   by the loaded distribution (ReflectionFunction) with the same file.
 * - fixtures/procedural/current.json freezes N itself: any change to the
 *   procedural surface, even an additive one, fails here until it is
 *   re-frozen deliberately (`generate-fixtures.php --current`).
 */
final class ProceduralSignatureSnapshotTest extends TestCase {

  private const LEGACY_TAGS = ['v0.6.0', 'v0.6.2', 'v0.6.3', 'v0.6.4', 'v0.6.5', 'v0.6.6'];

  private static ?array $current = null;

  /** @return array{functions: array<string, array<string, mixed>>, constants: array<string, string>} */
  private static function current(): array {
    return self::$current ??= ProceduralSignatures::fromSources(
      ProceduralSignatures::readTree(dirname(__DIR__, 3) . '/packages/ddd-wp/wordpress')
    );
  }

  /** @return array<string, mixed> */
  private static function fixture(string $name): array {
    $data = json_decode((string) file_get_contents(__DIR__ . "/fixtures/procedural/$name.json"), true);
    self::assertIsArray($data, "fixtures/procedural/$name.json");
    return $data;
  }

  /** @return iterable<string, array{string}> */
  public static function legacyTags(): iterable {
    foreach (self::LEGACY_TAGS as $tag) {
      yield $tag => [$tag];
    }
  }

  #[DataProvider('legacyTags')]
  public function test_every_procedural_function_of_the_tag_survives_with_a_compatible_signature(string $tag): void {
    $then = self::fixture($tag);
    $now = self::current();
    self::assertNotEmpty($then['functions'], "$tag snapshot is empty");

    $violations = [];
    foreach ($then['functions'] as $fqn => $sig) {
      if (!isset($now['functions'][$fqn])) {
        $violations[] = "$fqn ($tag {$sig['file']}) no longer exists";
        continue;
      }
      array_push($violations, ...ProceduralSignatures::compatibility($fqn, $sig, $now['functions'][$fqn]));
    }

    self::assertSame([], $violations, "Procedural ABI drift against $tag:\n" . implode("\n", $violations));
  }

  #[DataProvider('legacyTags')]
  public function test_every_namespace_constant_of_the_tag_survives(string $tag): void {
    $then = self::fixture($tag);
    $missing = array_values(array_diff(array_keys($then['constants']), array_keys(self::current()['constants'])));
    self::assertSame([], $missing, "constants of $tag missing from N");
  }

  public function test_the_schema_version_constant_never_goes_backwards(): void {
    $now = (int) self::current()['constants']['TangibleDDD\\WordPress\\DDD_SCHEMA_VERSION'];
    foreach (self::LEGACY_TAGS as $tag) {
      $then = self::fixture($tag)['constants']['TangibleDDD\\WordPress\\DDD_SCHEMA_VERSION'] ?? null;
      if ($then !== null) {
        self::assertGreaterThanOrEqual((int) $then, $now, "DDD_SCHEMA_VERSION below $tag's");
      }
    }
  }

  public function test_the_current_procedural_surface_matches_its_frozen_snapshot(): void {
    $frozen = self::fixture('current');
    unset($frozen['source']);

    self::assertSame(
      json_encode($frozen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      json_encode(self::current(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      'The procedural API of packages/ddd-wp/wordpress changed. If deliberate (and B14-compatible), re-freeze with `php tests/Unit/Abi/bin/generate-fixtures.php --current` in its own commit.'
    );
  }

  /** The rule itself: what B14 allows and what it refuses. */
  public function test_the_compatibility_rule(): void {
    $sig = static fn (array $params, ?string $ret = 'void') => ['file' => 'x.php', 'byRef' => false, 'returnType' => $ret, 'params' => $params];
    $p = static fn (string $name, ?string $type = null, ?string $default = null, bool $variadic = false) => compact('name', 'type', 'default', 'variadic') + ['byRef' => false];
    $then = $sig([$p('config', 'TangibleDDD\\Infra\\IDDDConfig'), $p('ttl', 'int', '30')]);

    self::assertSame([], ProceduralSignatures::compatibility('f', $then, $then));
    self::assertSame([], ProceduralSignatures::compatibility('f', $then, $sig([$p('config', 'TangibleDDD\\Infra\\IDDDConfig'), $p('ttl', 'int', '30'), $p('extra', '?int', 'null')])), 'an optional trailing parameter is allowed');
    self::assertSame([], ProceduralSignatures::compatibility('f', $sig([$p('a', 'int')]), $sig([$p('a', 'int', '0')])), 'a required parameter may gain a default');

    $refused = [
      'renamed (named arguments)' => $sig([$p('cfg', 'TangibleDDD\\Infra\\IDDDConfig'), $p('ttl', 'int', '30')]),
      'retyped' => $sig([$p('config', 'TangibleDDD\\Infra\\IConsumerIdentity'), $p('ttl', 'int', '30')]),
      'removed' => $sig([$p('config', 'TangibleDDD\\Infra\\IDDDConfig')]),
      'default changed' => $sig([$p('config', 'TangibleDDD\\Infra\\IDDDConfig'), $p('ttl', 'int', '60')]),
      'new required' => $sig([$p('config', 'TangibleDDD\\Infra\\IDDDConfig'), $p('ttl', 'int', '30'), $p('extra', 'int')]),
      'return type' => $sig([$p('config', 'TangibleDDD\\Infra\\IDDDConfig'), $p('ttl', 'int', '30')], 'bool'),
    ];
    foreach ($refused as $what => $now) {
      self::assertNotSame([], ProceduralSignatures::compatibility('f', $then, $now), "refused: $what");
    }
  }

  /**
   * The static reader and the runtime agree: every snapshotted function the
   * unit bootstrap loads is declared from the same file. (Files the unit
   * bootstrap does not include, e.g. the dashboard, are read statically only.)
   */
  public function test_loaded_procedural_functions_are_declared_where_the_snapshot_says(): void {
    $base = realpath(dirname(__DIR__, 3) . '/packages/ddd-wp/wordpress');
    $checked = 0;
    foreach (self::current()['functions'] as $fqn => $sig) {
      if (!function_exists($fqn)) {
        continue;
      }
      $ref = new \ReflectionFunction($fqn);
      self::assertSame($base . '/' . $sig['file'], realpath((string) $ref->getFileName()), "$fqn is declared elsewhere than the snapshot says");
      self::assertSame(count($sig['params']), $ref->getNumberOfParameters(), "$fqn parameter count (runtime vs static)");
      foreach ($ref->getParameters() as $i => $p) {
        self::assertSame($sig['params'][$i]['name'], $p->getName(), "$fqn parameter #$i name (runtime vs static)");
      }
      $checked++;
    }
    self::assertGreaterThan(30, $checked, 'the unit bootstrap loads the procedural files');
  }
}
