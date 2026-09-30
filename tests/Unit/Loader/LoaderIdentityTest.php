<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * The loader's self-identification invariant.
 *
 * The version-negotiation loader ranks copies by the string each copy passes
 * to Tangible_DDD_Versions::register() — NOT by the plugin header or the
 * TANGIBLE_DDD_VERSION constant. Four sites in tangible-ddd.php must
 * therefore advance in lockstep every release:
 *
 *   1. the `Version:` plugin header,
 *   2. the TANGIBLE_DDD_VERSION define,
 *   3. the register('X.Y.Z', ...) literal (the loader's ranking key), and
 *   4. the version-slugged function names (tangible_ddd_register_X_Y_Z /
 *      tangible_ddd_initialize_X_Y_Z — the per-copy namespace that lets N
 *      bundled copies coexist).
 *
 * They are separate BY DESIGN: the constant is first-copy-wins
 * (if !defined), so register() cannot read it — a later copy would register
 * under the first copy's version. Each file carries its own literal.
 *
 * History: the literal and the slugs were hand-maintained and froze at
 * 0.2.4 while releases advanced through 0.5.x — every 0.2.5+ copy claimed
 * to be 0.2.4, the function_exists guard let only the FIRST-LOADED copy
 * register, and "newest wins" silently degenerated to "first-loaded wins".
 * This test turns the convention into an invariant: bumping the header
 * without the literal and slugs fails the suite and cannot ship.
 */
class LoaderIdentityTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = file_get_contents(dirname(__DIR__, 3) . '/tangible-ddd.php');
    }

    private function header_version(): string
    {
        $this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s*\S+/m', $this->source);
        preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $this->source, $m);

        return $m[1];
    }

    public function test_the_constant_matches_the_plugin_header(): void
    {
        preg_match("/define\('TANGIBLE_DDD_VERSION',\s*'([^']+)'\)/", $this->source, $m);

        $this->assertSame(
            $this->header_version(),
            $m[1] ?? '(no define found)',
            'TANGIBLE_DDD_VERSION must equal the Version: header.'
        );
    }

    public function test_the_register_literal_matches_the_plugin_header(): void
    {
        // The ranking key the loader ACTUALLY uses. This is the line that
        // froze at 0.2.4 for five releases.
        preg_match(
            "/->register\(\s*'([^']+)',/",
            $this->source,
            $m
        );

        $this->assertSame(
            $this->header_version(),
            $m[1] ?? '(no register literal found)',
            'The register() version literal is the loader\'s ranking key — it must equal the Version: header.'
        );
    }

    public function test_the_function_slugs_match_the_plugin_header(): void
    {
        $slug = str_replace(['.', '-'], '_', $this->header_version());

        foreach (["tangible_ddd_register_{$slug}", "tangible_ddd_initialize_{$slug}"] as $fn) {
            $this->assertStringContainsString(
                "function {$fn}(",
                $this->source,
                "Version-named function {$fn} must exist — the slugs are the per-copy namespace; a stale slug lets the first-loaded copy monopolise registration."
            );
        }

        // And no stale slug from a previous release may survive anywhere in
        // the file (definitions, add_action string refs, closure calls).
        preg_match_all('/tangible_ddd_(?:register|initialize)_(\d+_\d+_\d+)/', $this->source, $all);
        foreach (array_unique($all[1]) as $found_slug) {
            $this->assertSame(
                $slug,
                $found_slug,
                "Stale version slug _{$found_slug} survives in tangible-ddd.php — every slugged reference must carry the current release's slug."
            );
        }
    }

    /**
     * The PHP floor, stated once per artifact, must agree everywhere.
     *
     * The locked Symfony 7.4 graph and PHPUnit 11 already need 8.2 (register
     * X2, report F-1), so `>=8.1` in the manifest and `Requires PHP: 8.1` in
     * the header claimed a floor nothing could run on. The plugin header is
     * what WordPress enforces at activation; composer.json is what Composer
     * enforces at install. Each packages/* manifest is a separately
     * installable artifact and carries the same floor (register section 2).
     */
    public function test_the_php_floor_agrees_across_header_and_every_manifest(): void
    {
        preg_match('/^\s*\*\s*Requires PHP:\s*(\S+)/m', $this->source, $m);
        $this->assertSame(self::PHP_FLOOR, $m[1] ?? '(no Requires PHP header)', 'Plugin header Requires PHP.');

        foreach ($this->manifests() as $rel => $manifest) {
            $this->assertSame(
                '>=' . self::PHP_FLOOR,
                $manifest['require']['php'] ?? '(no php requirement)',
                "{$rel} must require php >=" . self::PHP_FLOOR . ' (register section 2).'
            );
        }
    }

    /**
     * No manifest may pin a version that disagrees with the loader identity.
     *
     * Composer takes the root version from the VCS tag, and each package
     * resolves siblings through `self.version`, so a stale `version` field in
     * any manifest would silently shadow the tag. Either leave it out (the
     * default) or keep it equal to the plugin header.
     */
    public function test_no_manifest_declares_a_version_other_than_the_header(): void
    {
        foreach ($this->manifests() as $rel => $manifest) {
            if (!array_key_exists('version', $manifest)) {
                $this->addToAssertionCount(1);
                continue;
            }
            $this->assertSame(
                $this->header_version(),
                $manifest['version'],
                "{$rel} declares a version that disagrees with the plugin header."
            );
        }
    }

    private const PHP_FLOOR = '8.2';

    /** @return array<string, array<string, mixed>> repo-relative path => decoded manifest */
    private function manifests(): array
    {
        $root = dirname(__DIR__, 3);
        $paths = array_merge([$root . '/composer.json'], glob($root . '/packages/*/composer.json') ?: []);

        $out = [];
        foreach ($paths as $path) {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $out[substr($path, strlen($root) + 1)] = $decoded;
        }

        return $out;
    }

    public function test_the_winner_loads_the_module_facade_after_the_host_hooks(): void
    {
        // THE RELEASE PIN — bump this with every tag. This literal is the
        // only tree-visible tie between the git tag and the loader identity;
        // v0.6.3 shipped registering as 0.6.2 because the tag ritual skipped
        // the loader bump and nothing in CI could see the tag.
        $this->assertSame('0.6.6', $this->header_version());

        $hooks = strpos($this->source, "'ddd-wordpress/hooks.php'");
        $modules = strpos($this->source, "'ddd-wordpress/modules.php'");

        $this->assertNotFalse($hooks, 'The winner must load the host hook facade.');
        $this->assertNotFalse($modules, 'The winner must load the consumer-module facade.');
        $this->assertGreaterThan(
            $hooks,
            $modules,
            'modules.php depends on the process and listener helpers from hooks.php.',
        );
    }
}
