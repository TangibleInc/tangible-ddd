<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The layer rules in deptrac.yaml are part of the contract (register 1.2,
 * X4, B24). Running deptrac is CI's job (`vendor/bin/deptrac analyse`, 0
 * violations); this guards the rules themselves against quiet widening.
 */
final class DeptracConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(): array
    {
        $path = dirname(__DIR__, 3) . '/deptrac.yaml';
        self::assertFileExists($path);

        return Yaml::parseFile($path)['deptrac'];
    }

    public function test_portable_core_may_depend_on_no_collected_layer(): void
    {
        $ruleset = self::config()['ruleset'];

        $this->assertArrayHasKey('Core', $ruleset);
        $this->assertNull($ruleset['Core'], 'portable core reaches only PHP, tactician and PSR (uncollected)');
        $this->assertSame(['Core'], $ruleset['PdoDefault'], 'pdo-default builds on core only');
        $this->assertSame(['Core', 'Symfony'], $ruleset['CoreDiBridge'], 'the DI bridge is the one Symfony door in core (X4)');
        $this->assertNotContains('PdoDefault', $ruleset['DddWp'], 'ddd-wp never uses Defaults/Pdo (B24)');
        $this->assertNotContains('WordPress', $ruleset['DddSymfony']);
        $this->assertNotContains('DddWp', $ruleset['DddSymfony']);
        foreach (['WordPress', 'Symfony', 'Makina', 'Doctrine'] as $vendor) {
            $this->assertNull($ruleset[$vendor]);
        }
    }

    public function test_the_core_layer_excludes_exactly_the_two_fenced_corners(): void
    {
        $layers = array_column(self::config()['layers'], 'collectors', 'name');
        $core = $layers['Core'][0];

        $this->assertSame('bool', $core['type']);
        $this->assertSame([['type' => 'directory', 'value' => 'packages/ddd-core/src/.*']], $core['must']);
        $this->assertSame(
            ['packages/ddd-core/src/Defaults/Pdo/.*', 'packages/ddd-core/src/Infra/DependencyInjection/.*'],
            array_column($core['must_not'], 'value')
        );
    }

    public function test_skipped_violations_are_only_split_deferred_classes_still_in_ddd_wp(): void
    {
        $root = dirname(__DIR__, 3);
        $skips = self::config()['skip_violations'] ?? [];

        foreach ($skips as $depender => $targets) {
            $this->assertFileExists(
                $root . '/packages/ddd-core/src/' . str_replace('\\', '/', substr($depender, strlen('TangibleDDD\\'))) . '.php',
                "{$depender}: only core files may carry a skip"
            );
            foreach ($targets as $target) {
                if (str_ends_with($target, '()')) {
                    // the procedural API a split-deferred parent calls
                    $this->assertStringStartsWith('TangibleDDD\\WordPress\\', $target);
                    continue;
                }
                $this->assertFileExists(
                    $root . '/packages/ddd-wp/src/' . str_replace('\\', '/', substr($target, strlen('TangibleDDD\\'))) . '.php',
                    "{$depender} -> {$target}: a skip may only name a split-deferred class still in packages/ddd-wp/src"
                );
            }
        }
        $this->assertLessThanOrEqual(10, count($skips), 'the skip list only ever shrinks (wave 2 round 1 had 10 dependers)');
    }
}
