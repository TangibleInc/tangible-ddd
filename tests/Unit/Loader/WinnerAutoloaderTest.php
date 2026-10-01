<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * The winner's prepended autoloader (register 1.5; report B7, F-13): maps
 * both package directories and the root classmap, consults the compat/ alias
 * map, and reports a fall-through to another copy without ever throwing.
 *
 * Fixture classes are written to scratch "copies" so the tests never depend
 * on what the real tree happens to contain.
 */
final class WinnerAutoloaderTest extends TestCase
{
    private string $scratch;

    /** @var list<ClassLoader> */
    private array $extra_loaders = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/ddd-winner-' . bin2hex(random_bytes(4));
        mkdir($this->scratch, 0777, true);
        \Tangible_DDD_Load_Diagnostics::reset();
        \Tangible_DDD_Load_Diagnostics::use_log(function (string $line): void {
            $this->logged[] = $line;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->extra_loaders as $loader) {
            $loader->unregister();
        }
        \Tangible_DDD_Load_Diagnostics::use_log(null);
        \Tangible_DDD_Load_Diagnostics::reset();
        $this->rmrf($this->scratch);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** Write a class file for $fqcn under $dir/$rel and return its path. */
    private function write_class(string $dir, string $rel, string $fqcn, string $body = ''): string
    {
        $ns = substr($fqcn, 0, (int) strrpos($fqcn, '\\'));
        $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
        $path = $dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<?php\nnamespace {$ns};\nclass {$short} {{$body}}\n");

        return $path;
    }

    private static function unique(string $stem): string
    {
        return $stem . bin2hex(random_bytes(4));
    }

    /** Register a Composer loader for $prefix at $dir, appended after the stack. */
    private function other_copy(string $prefix, string $dir): void
    {
        $loader = new ClassLoader();
        $loader->addPsr4($prefix, $dir);
        $loader->register(false);
        $this->extra_loaders[] = $loader;
    }

    public function test_it_maps_core_wp_and_wordpress_names_to_the_winner_package_directories(): void
    {
        $autoloader = new \Tangible_DDD_Winner_Autoloader(self::root(), '0.7.0');
        $root = self::root();

        $this->assertSame(
            $root . '/packages/ddd-core/src/Application/Process/LongProcess.php',
            $autoloader->file_for('TangibleDDD\\Application\\Process\\LongProcess')
        );
        $this->assertSame(
            $root . '/packages/ddd-wp/src/Infra/Persistence/OutboxRepository.php',
            $autoloader->file_for('TangibleDDD\\Infra\\Persistence\\OutboxRepository'),
            'a moved FQCN resolves from ddd-wp/src under the same prefix'
        );
        $this->assertSame(
            $root . '/packages/ddd-wp/wordpress/Admin/Dashboard/AdminPage.php',
            $autoloader->file_for('\\TangibleDDD\\WordPress\\Admin\\Dashboard\\AdminPage')
        );
        $this->assertNull($autoloader->file_for('TangibleDDD\\No\\Such\\Thing'));
        $this->assertNull($autoloader->file_for('League\\Tactician\\CommandBus'));
    }

    public function test_it_serves_the_classmapped_cli_and_self_consumer_classes(): void
    {
        $autoloader = new \Tangible_DDD_Winner_Autoloader(self::root(), '0.7.0');

        $this->assertSame(
            self::root() . '/packages/ddd-wp/wordpress/cli/class-ddd-command.php',
            $autoloader->file_for('TangibleDDD\\WordPress\\CLI\\DDD_Command')
        );
        $this->assertSame(
            self::root() . '/packages/ddd-wp/wordpress/self/HandlerClassNameInflector.php',
            $autoloader->file_for('TangibleDDD\\WordPress\\SelfConsumer\\HandlerClassNameInflector')
        );
    }

    public function test_it_loads_from_core_before_wp_and_from_the_wordpress_directory(): void
    {
        $copy = $this->scratch . '/winner';
        $core = 'TangibleDDD\\Fx\\' . self::unique('CoreThing');
        $wp = 'TangibleDDD\\WordPress\\Fx\\' . self::unique('WpThing');
        $short = substr($core, strlen('TangibleDDD\\'));
        $this->write_class($copy, 'packages/ddd-core/src/' . str_replace('\\', '/', $short) . '.php', $core);
        $this->write_class($copy, 'packages/ddd-wp/src/' . str_replace('\\', '/', $short) . '.php', $core);
        $wp_file = $this->write_class($copy, 'packages/ddd-wp/wordpress/Fx/' . substr($wp, strrpos($wp, '\\') + 1) . '.php', $wp);

        $autoloader = new \Tangible_DDD_Winner_Autoloader($copy, '0.7.0');
        $autoloader->load($core);
        $autoloader->load($wp);

        $this->assertTrue(class_exists($core, false));
        $this->assertStringContainsString('/packages/ddd-core/src/', (string) (new \ReflectionClass($core))->getFileName());
        $this->assertSame(realpath($wp_file), realpath((string) (new \ReflectionClass($wp))->getFileName()));
        $this->assertSame([], \Tangible_DDD_Load_Diagnostics::findings());
    }

    public function test_a_compat_alias_resolves_to_its_target_in_the_winner(): void
    {
        $copy = $this->scratch . '/winner';
        $target = 'TangibleDDD\\Fx\\' . self::unique('Renamed');
        $legacy = 'TangibleDDD\\Fx\\' . self::unique('OldName');
        $this->write_class($copy, 'packages/ddd-core/src/Fx/' . substr($target, 15) . '.php', $target);
        $autoloader = new \Tangible_DDD_Winner_Autoloader($copy, '0.7.0', [$legacy => $target]);
        $autoloader->register();

        try {
            $this->assertTrue(class_exists($legacy));
            $this->assertSame($target, (new \ReflectionClass($legacy))->getName());
        } finally {
            spl_autoload_unregister([$autoloader, 'load']);
        }
    }

    public function test_an_alias_whose_target_does_not_resolve_is_reported_not_thrown(): void
    {
        $legacy = 'TangibleDDD\\Fx\\' . self::unique('Dangling');
        $autoloader = new \Tangible_DDD_Winner_Autoloader($this->scratch, '0.7.0', [$legacy => 'TangibleDDD\\Fx\\Nowhere']);

        $autoloader->load($legacy);

        $this->assertFalse(class_exists($legacy, false));
        $this->assertCount(1, \Tangible_DDD_Load_Diagnostics::findings('compat-map'));
    }

    public function test_a_name_another_copy_serves_is_reported_as_a_fall_through_and_left_to_that_copy(): void
    {
        $winner = $this->scratch . '/winner';
        mkdir($winner);
        $legacy = $this->scratch . '/legacy/ddd-src';
        $fqcn = 'TangibleDDD\\Application\\Fx\\' . self::unique('OnlyInLegacy');
        $file = $this->write_class($legacy, 'Application/Fx/' . substr($fqcn, strrpos($fqcn, '\\') + 1) . '.php', $fqcn);
        $this->other_copy('TangibleDDD\\', $legacy);

        $autoloader = new \Tangible_DDD_Winner_Autoloader($winner, '0.7.0');
        $autoloader->load($fqcn);

        $this->assertFalse(class_exists($fqcn, false), 'the winner does not load another copy\'s file itself');
        $findings = \Tangible_DDD_Load_Diagnostics::findings('fall-through');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString($fqcn, $findings[0]['message']);
        $this->assertStringContainsString($file, $findings[0]['message']);
        $this->assertStringContainsString('0.7.0', $findings[0]['message']);
        $this->assertCount(1, $this->logged);
        $this->assertStringStartsWith('[tangible-ddd] fall-through:', $this->logged[0]);

        $autoloader->load($fqcn);
        $this->assertCount(1, $this->logged, 'each fall-through is logged once per request');
    }

    public function test_a_probe_for_a_name_nobody_serves_is_silent(): void
    {
        $autoloader = new \Tangible_DDD_Winner_Autoloader($this->scratch, '0.7.0');
        $autoloader->register();

        try {
            $this->assertFalse(class_exists('TangibleDDD\\Nope\\' . self::unique('Missing')));
        } finally {
            spl_autoload_unregister([$autoloader, 'load']);
        }
        $this->assertSame([], \Tangible_DDD_Load_Diagnostics::findings());
        $this->assertSame([], $this->logged);
    }

    public function test_foreign_packages_and_files_inside_the_winner_are_not_fall_throughs(): void
    {
        $winner = $this->scratch . '/winner';
        $sf = 'TangibleDDD\\Symfony\\Fx\\' . self::unique('Bundle');
        $this->write_class($this->scratch . '/sf', 'Fx/' . substr($sf, strrpos($sf, '\\') + 1) . '.php', $sf);
        $this->other_copy('TangibleDDD\\Symfony\\', $this->scratch . '/sf');
        $inside = 'TangibleDDD\\Extra\\' . self::unique('Inside');
        $this->write_class($winner . '/extra', substr($inside, strrpos($inside, '\\') + 1) . '.php', $inside);
        $this->other_copy('TangibleDDD\\Extra\\', $winner . '/extra');

        $autoloader = new \Tangible_DDD_Winner_Autoloader($winner, '0.7.0');
        $autoloader->load($sf);
        $autoloader->load($inside);

        $this->assertSame([], \Tangible_DDD_Load_Diagnostics::findings());
    }

    public function test_the_compat_map_ships_and_every_alias_targets_a_winner_class(): void
    {
        $map = \Tangible_DDD_Winner_Autoloader::aliases_from(self::root() . '/compat/aliases.php');
        $this->assertSame([], \Tangible_DDD_Load_Diagnostics::findings('compat-map'), 'the shipped map is well-formed');

        $winner = new \Tangible_DDD_Winner_Autoloader(self::root(), '0.7.0');
        foreach ($map as $legacy => $current) {
            $this->assertNull($winner->file_for($legacy), "{$legacy} is aliased, so it must not also exist as a file (R1)");
            $this->assertNotNull($winner->file_for($current), "{$legacy} => {$current}: the target ships in the distribution");
        }
    }

    public function test_a_malformed_compat_map_yields_no_aliases_and_a_diagnostic(): void
    {
        file_put_contents($this->scratch . '/bad.php', "<?php return ['Not\\\\Ours' => 'TangibleDDD\\\\X', 'TangibleDDD\\\\Ok' => 'TangibleDDD\\\\Target'];");
        file_put_contents($this->scratch . '/worse.php', '<?php return 42;');

        $this->assertSame(['TangibleDDD\\Ok' => 'TangibleDDD\\Target'], \Tangible_DDD_Winner_Autoloader::aliases_from($this->scratch . '/bad.php'));
        $this->assertSame([], \Tangible_DDD_Winner_Autoloader::aliases_from($this->scratch . '/worse.php'));
        $this->assertSame([], \Tangible_DDD_Winner_Autoloader::aliases_from($this->scratch . '/absent.php'));
        $this->assertCount(2, \Tangible_DDD_Load_Diagnostics::findings('compat-map'));
    }

    public function test_the_live_winner_is_prepended_ahead_of_composer_for_this_distribution(): void
    {
        // tests/bootstrap.php -> vendor/autoload.php -> loader/tangible-ddd-0_7_0.php
        // -> tangible-ddd.php, which initialises immediately outside WordPress.
        $stack = spl_autoload_functions();
        $winner_at = null;
        $composer_at = null;
        foreach ($stack as $i => $fn) {
            if ($winner_at === null && is_array($fn) && $fn[0] instanceof \Tangible_DDD_Winner_Autoloader) {
                $winner_at = $i;
                $this->assertSame(realpath(self::root()), realpath($fn[0]->root()));
                $this->assertSame('0.7.0', $fn[0]->version());
            }
            if ($composer_at === null && is_array($fn) && $fn[0] instanceof ClassLoader) {
                $composer_at = $i;
            }
        }

        $this->assertNotNull($winner_at, 'the winner registered its autoloader');
        $this->assertNotNull($composer_at);
        $this->assertLessThan($composer_at, $winner_at, 'the winner is consulted before any Composer map');
    }
}
