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
}
