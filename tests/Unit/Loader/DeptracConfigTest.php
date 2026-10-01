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

    /**
     * The wave-2 skips for core files that depended on split-deferred
     * classes (CR-PK-5) expired with the round-2 splits. From wave 4 no
     * violation may be skipped at all; tests/Compat/check-allowances.php
     * enforces the same in `run.sh compat`.
     */
    public function test_no_violation_is_skipped(): void
    {
        $this->assertArrayNotHasKey('skip_violations', self::config(), 'CR-PK-5: the transitional deptrac skips have expired');
    }
}
