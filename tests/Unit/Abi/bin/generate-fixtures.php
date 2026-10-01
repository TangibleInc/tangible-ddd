<?php
/**
 * Regenerates the ABI freeze fixtures of tests/Unit/Abi (register section 8,
 * wave 2 wp bullet) from the legacy git tags. Run from the repository root,
 * with the tags fetched:
 *
 *   php tests/Unit/Abi/bin/generate-fixtures.php            legacy tags (B14 snapshots, B9 scaffolds)
 *   php tests/Unit/Abi/bin/generate-fixtures.php --current  re-freeze N's own procedural snapshot
 *
 * The fixtures are committed; the tests never call git. Regenerating the
 * legacy fixtures must be a no-op (they describe released tags). Re-freezing
 * the current snapshot is a deliberate ABI change and belongs in its own
 * reviewed commit.
 *
 * Outputs:
 *   fixtures/procedural/<tag>.json       B14: functions + constants under ddd-wordpress/ at <tag>
 *   fixtures/procedural/current.json     B14: the same for packages/ddd-wp/wordpress/ (N)
 *   fixtures/scaffold/<tag>/...          B9: what `wp ddd init acme_orders AcmeOrders ACME_ORDERS_VERSION`
 *                                        wrote at <tag> (DI files and the directory skeleton);
 *                                        a tag whose templates equal an earlier tag's is an alias
 *   fixtures/scaffold/manifest.json      tag => fixture directory, plus the source blob of the scaffolder
 */

declare(strict_types=1);

use TangibleDDD\Tests\Unit\Abi\Support\ProceduralSignatures;

const LEGACY_TAGS = ['v0.6.0', 'v0.6.2', 'v0.6.3', 'v0.6.4', 'v0.6.5', 'v0.6.6'];
const SCAFFOLD_ARGS = ['acme_orders', 'AcmeOrders', 'ACME_ORDERS_VERSION'];

$root = getcwd();
require $root . '/vendor/autoload.php';
$fixtures = dirname(__DIR__) . '/fixtures';

function git(string ...$args): string {
  $cmd = 'git ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
  exec($cmd, $out, $status);
  if ($status !== 0) {
    fwrite(STDERR, "generate-fixtures: `$cmd` failed\n");
    exit(1);
  }
  return implode("\n", $out);
}

function write_json(string $path, array $data): void {
  @mkdir(dirname($path), 0777, true);
  file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

if (in_array('--current', $argv, true)) {
  $snapshot = ProceduralSignatures::fromSources(ProceduralSignatures::readTree($root . '/packages/ddd-wp/wordpress'));
  write_json("$fixtures/procedural/current.json", ['source' => 'packages/ddd-wp/wordpress'] + $snapshot);
  echo "froze fixtures/procedural/current.json (" . count($snapshot['functions']) . " functions)\n";
  exit(0);
}

$manifest = [];
$byContent = [];
foreach (LEGACY_TAGS as $tag) {
  // ── B14: procedural snapshot ──
  $sources = [];
  foreach (explode("\n", git('ls-tree', '-r', '--name-only', $tag, 'ddd-wordpress')) as $path) {
    if (str_ends_with($path, '.php')) {
      $sources[substr($path, strlen('ddd-wordpress/'))] = git('show', "$tag:$path") . "\n";
    }
  }
  write_json("$fixtures/procedural/$tag.json", ['source' => "$tag:ddd-wordpress"] + ProceduralSignatures::fromSources($sources));

  // ── B9: scaffold templates ──
  $blob = git('rev-parse', "$tag:ddd-wordpress/cli/class-ddd-command.php");
  $class = 'DDD_Command_' . str_replace('.', '_', $tag);
  $code = preg_replace('/^class DDD_Command\b/m', "class $class", git('show', "$tag:ddd-wordpress/cli/class-ddd-command.php"), 1, $count);
  if ($count !== 1) {
    fwrite(STDERR, "generate-fixtures: no `class DDD_Command` in $tag\n");
    exit(1);
  }
  $tmp = tempnam(sys_get_temp_dir(), 'ddd-scaffold-') . '.php';
  file_put_contents($tmp, $code . "\n");
  require $tmp;
  unlink($tmp);
  $fqcn = 'TangibleDDD\\WordPress\\CLI\\' . $class;
  $command = (new ReflectionClass($fqcn))->newInstanceWithoutConstructor();
  $templates = (new ReflectionMethod($fqcn, 'get_templates'))->invoke($command, ...SCAFFOLD_ARGS);

  // Only what the container build reads: the DI files and the skeleton the
  // resource globs point at (.gitkeep keeps the empty directories in git).
  $templates = array_filter($templates, static fn (string $f) => str_starts_with($f, 'ddd-wordpress/') || str_starts_with($f, 'ddd-src/'), ARRAY_FILTER_USE_KEY);
  ksort($templates);
  $hash = sha1(serialize($templates));

  if (isset($byContent[$hash])) {
    $manifest[$tag] = ['dir' => $byContent[$hash], 'scaffolder_blob' => $blob];
    continue;
  }
  $byContent[$hash] = $tag;
  $manifest[$tag] = ['dir' => $tag, 'scaffolder_blob' => $blob];
  foreach ($templates as $file => $content) {
    $path = "$fixtures/scaffold/$tag/$file";
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
  }
}

write_json("$fixtures/scaffold/manifest.json", [
  'args' => SCAFFOLD_ARGS,
  'tags' => $manifest,
]);
echo "wrote fixtures for " . implode(', ', LEGACY_TAGS) . "\n";
