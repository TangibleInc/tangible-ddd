<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * The package manifests are part of the contract (register sections 1.1,
 * 1.5 and 2). These assertions pin the dependency closure of each package so
 * a stray `require` cannot widen what a WordPress site or a plain-PHP host
 * installs.
 */
class PackageManifestTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> */
    private static function manifest(string $rel): array
    {
        $path = self::root() . '/' . $rel;
        self::assertFileExists($path, "{$rel} must exist.");

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_symfony_yaml_is_a_runtime_requirement_of_the_root(): void
    {
        // The self-consumer boots a YamlFileLoader container; with yaml only
        // in require-dev, a --no-dev install loses the framework's own
        // replay/discard/retry/purge commands silently (report F-5, O11).
        $root = self::manifest('composer.json');

        $this->assertSame('^7.4', $root['require']['symfony/yaml'] ?? null);
        $this->assertArrayNotHasKey('symfony/yaml', $root['require-dev'] ?? []);
    }

    public function test_the_root_requires_everything_the_core_it_replaces_requires(): void
    {
        // The root replaces tangible/ddd-core, so Composer never reads core's
        // manifest inside the root graph: each core runtime requirement must
        // be restated here or a WordPress install misses it. psr/log arrives
        // with the PSR-3 loggers of the wave-2 runtime classes (wave-1 notes).
        $root = self::manifest('composer.json');
        $core = self::manifest('packages/ddd-core/composer.json');

        $this->assertSame(
            ['tangible/ddd-core' => 'self.version', 'tangible/ddd-wp' => 'self.version'],
            $root['replace'] ?? null
        );
        foreach ($core['require'] as $pkg => $constraint) {
            $this->assertSame($constraint, $root['require'][$pkg] ?? null, "root must restate core's {$pkg} {$constraint}");
        }
        $this->assertSame('^1|^2|^3', $root['require']['psr/log'] ?? null);
    }

    public function test_the_root_carries_no_symfony_host_dependencies(): void
    {
        // ddd-symfony is not part of the WordPress distribution (register
        // 1.1, report F section 3 rule 2): a WP consumer's graph must not grow.
        $root = self::manifest('composer.json');

        foreach (['doctrine/dbal', 'symfony/messenger', 'symfony/framework-bundle', 'tangible/ddd-symfony'] as $pkg) {
            $this->assertArrayNotHasKey($pkg, $root['require'], "root must not require {$pkg}");
        }
        $this->assertStringNotContainsString(
            'ddd-symfony',
            json_encode([$root['autoload'] ?? [], $root['replace'] ?? []], JSON_THROW_ON_ERROR),
            'ddd-symfony is neither autoloaded nor replaced by the root.'
        );
    }

    public function test_ddd_core_has_the_frozen_dependency_closure(): void
    {
        $core = self::manifest('packages/ddd-core/composer.json');

        $this->assertSame('tangible/ddd-core', $core['name']);
        $this->assertSame(
            [
                'php' => '>=8.2',
                'league/tactician' => '^2.0-rc1',
                'psr/container' => '^1.1|^2.0',
                'psr/log' => '^1|^2|^3',
            ],
            $core['require'],
            'ddd-core runtime closure is exactly {tactician, psr/container, psr/log} (register 1.1).'
        );
        $this->assertArrayHasKey('ext-pdo', $core['suggest'] ?? []);
        $this->assertArrayHasKey('symfony/dependency-injection', $core['suggest'] ?? []);
        // The DI bridge (X4) is compiled against symfony/dependency-injection in dev only.
        $this->assertArrayHasKey('symfony/dependency-injection', $core['require-dev'] ?? []);
        $this->assertSame(['TangibleDDD\\' => 'src/'], $core['autoload']['psr-4'] ?? null);
        // Register 1.5: the guarded assert helper is core's only files entry;
        // no loader, registry or WordPress file is ever autoloaded by core.
        $this->assertSame(['src/Domain/Shared/assert.php'], $core['autoload']['files'] ?? null);
        $this->assertArrayNotHasKey('classmap', $core['autoload']);
    }

    public function test_ddd_wp_requires_its_matched_core_and_the_wordpress_closure(): void
    {
        $wp = self::manifest('packages/ddd-wp/composer.json');

        $this->assertSame('tangible/ddd-wp', $wp['name']);
        $this->assertSame(
            [
                'php' => '>=8.2',
                'tangible/ddd-core' => 'self.version',
                'woocommerce/action-scheduler' => '^3.9',
                'symfony/dependency-injection' => '^7.4',
                'symfony/config' => '^7.4',
                'symfony/yaml' => '^7.4',
                'makinacorpus/query-builder' => '^1.6',
            ],
            $wp['require'],
            'ddd-wp pins its sibling core to the same version (matched pair, register 1.5).'
        );
    }

    public function test_ddd_symfony_requires_core_and_the_symfony_host_closure(): void
    {
        $sf = self::manifest('packages/ddd-symfony/composer.json');

        $this->assertSame('tangible/ddd-symfony', $sf['name']);
        $this->assertSame(
            [
                'php' => '>=8.2',
                'tangible/ddd-core' => 'self.version',
                'doctrine/dbal' => '^4',
                'symfony/messenger' => '^7.4',
                // The relay's transport is Messenger's Doctrine transport on the DBAL connection.
                'symfony/doctrine-messenger' => '^7.4',
                'symfony/framework-bundle' => '^7.4',
            ],
            $sf['require']
        );
        $this->assertSame(['TangibleDDD\\Symfony\\' => 'src/'], $sf['autoload']['psr-4'] ?? null);
    }

    public function test_adapter_packages_allow_the_tactician_rc_when_they_are_the_root(): void
    {
        // O20, verified in wave 1: Composer honours the RC stability flag of
        // `^2.0-rc1` only in the ROOT manifest. As a transitive requirement of
        // ddd-core it does not resolve under minimum-stability stable, so any
        // root (TXP, a plain-PHP host, each adapter's own CI) must restate it.
        // require-dev is inert when the adapter is installed as a dependency.
        foreach (['packages/ddd-wp/composer.json', 'packages/ddd-symfony/composer.json'] as $rel) {
            $this->assertSame(
                '^2.0-rc1',
                self::manifest($rel)['require-dev']['league/tactician'] ?? null,
                "{$rel} must restate league/tactician ^2.0-rc1 in require-dev (O20)."
            );
        }
    }

    public function test_sibling_packages_resolve_core_through_a_copying_path_repository(): void
    {
        // symlink:false copies files, so a package's own CI catches files
        // that exist only through the monorepo (report F section 3 rule 3).
        foreach (['packages/ddd-wp/composer.json', 'packages/ddd-symfony/composer.json'] as $rel) {
            $repos = self::manifest($rel)['repositories'] ?? [];
            $this->assertContains(
                ['type' => 'path', 'url' => '../ddd-core', 'options' => ['symlink' => false]],
                $repos,
                "{$rel} resolves tangible/ddd-core from ../ddd-core without symlinks."
            );
        }
    }
}
