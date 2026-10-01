<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * Winner-boot diagnostics (register 7.2 load.preloaded-class and
 * load.v0-2-negative; rulings X1): a pre-window copy and a class loaded from
 * another copy are both reported, never silent and never thrown.
 */
final class LoadDiagnosticsTest extends TestCase
{
    private string $scratch;

    /** @var list<string> */
    private array $logged = [];

    /** @var list<ClassLoader> */
    private array $extra_loaders = [];

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/ddd-diag-' . bin2hex(random_bytes(4));
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
        exec('rm -rf ' . escapeshellarg($this->scratch));
    }

    /** A Composer loader over a fake 0.2.5 ddd-src holding the pre-window classes. */
    private function legacy_02_copy(): string
    {
        $src = $this->scratch . '/fx-legacy-0_2_5/vendor/tangible/ddd/ddd-src';
        foreach (\Tangible_DDD_Load_Diagnostics::PRE_WINDOW_FQCNS as $fqcn) {
            $rel = str_replace('\\', '/', substr($fqcn, strlen('TangibleDDD\\'))) . '.php';
            @mkdir(dirname($src . '/' . $rel), 0777, true);
            file_put_contents($src . '/' . $rel, "<?php\nthrow new \\LogicException('a probe must never load {$fqcn}');\n");
        }
        $loader = new ClassLoader();
        $loader->addPsr4('TangibleDDD\\', $src);
        $loader->register(false);
        $this->extra_loaders[] = $loader;

        return $src;
    }

    public function test_the_pre_window_fqcns_exist_in_v0_2_5_and_in_no_in_window_tag(): void
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            $this->markTestSkipped('not a git checkout');
        }
        $files = static function (string $ref) use ($root): array {
            exec('git -C ' . escapeshellarg($root) . ' ls-tree -r --name-only ' . escapeshellarg($ref) . ' -- ddd-src 2>/dev/null', $out, $code);

            return $code === 0 ? $out : [];
        };
        $v025 = $files('v0.2.5');
        if ($v025 === []) {
            $this->markTestSkipped('tag v0.2.5 not available');
        }
        foreach (\Tangible_DDD_Load_Diagnostics::PRE_WINDOW_FQCNS as $fqcn) {
            $rel = 'ddd-src/' . str_replace('\\', '/', substr($fqcn, strlen('TangibleDDD\\'))) . '.php';
            $this->assertContains($rel, $v025, "{$fqcn} is a 0.2.x class");
            foreach (['v0.6.2', 'v0.6.5', 'v0.6.6'] as $tag) {
                $this->assertNotContains($rel, $files($tag), "{$fqcn} must not exist in in-window {$tag} (false positive)");
            }
            $this->assertFalse(
                (new \Tangible_DDD_Winner_Autoloader($root, '0.7.0'))->file_for($fqcn) !== null,
                "{$fqcn} must not exist in this distribution"
            );
        }
    }

    public function test_a_registered_copy_below_the_window_is_evidence(): void
    {
        $evidence = \Tangible_DDD_Load_Diagnostics::pre_window_evidence(
            ['0.2.4' => '/plugins/reporting/vendor/tangible/ddd', '0.6.5' => '/lms', '0.7.0' => '/n'],
            []
        );

        $this->assertCount(1, $evidence);
        $this->assertStringContainsString('0.2.4', $evidence[0]);
        $this->assertStringContainsString('/plugins/reporting/vendor/tangible/ddd', $evidence[0]);
    }

    public function test_a_pre_window_class_some_loader_would_serve_is_evidence_without_loading_it(): void
    {
        $src = $this->legacy_02_copy();

        $evidence = \Tangible_DDD_Load_Diagnostics::pre_window_evidence(['0.7.0' => '/n']);

        $this->assertCount(count(\Tangible_DDD_Load_Diagnostics::PRE_WINDOW_FQCNS), $evidence);
        $this->assertStringContainsString($src, $evidence[0]);
        foreach (\Tangible_DDD_Load_Diagnostics::PRE_WINDOW_FQCNS as $fqcn) {
            $this->assertFalse(class_exists($fqcn, false));
        }
    }

    public function test_in_window_copies_alone_produce_no_finding(): void
    {
        \Tangible_DDD_Load_Diagnostics::at_winner_boot(
            '0.7.0',
            dirname(__DIR__, 3),
            ['0.6.2' => '/a', '0.6.5' => '/b', '0.7.0' => dirname(__DIR__, 3)],
            true,
            [\TangibleDDD\Application\Process\LongProcess::class]
        );

        $this->assertSame([], \Tangible_DDD_Load_Diagnostics::findings());
        $this->assertSame([], $this->logged);
    }

    public function test_with_debug_on_a_pre_window_copy_raises_the_named_error_and_logs_it(): void
    {
        $raised = [];
        set_error_handler(static function (int $no, string $msg) use (&$raised): bool {
            $raised[] = [$no, $msg];

            return true;
        });
        try {
            \Tangible_DDD_Load_Diagnostics::at_winner_boot('0.7.0', dirname(__DIR__, 3), ['0.2.4' => '/r', '0.7.0' => '/n'], true, []);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $raised);
        $this->assertSame(E_USER_WARNING, $raised[0][0]);
        $this->assertStringStartsWith('TANGIBLE_DDD_UNSUPPORTED_VERSION: ', $raised[0][1]);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('TANGIBLE_DDD_UNSUPPORTED_VERSION', $this->logged[0]);
        $this->assertCount(1, \Tangible_DDD_Load_Diagnostics::findings('unsupported-version'));
    }

    public function test_with_debug_off_a_pre_window_copy_is_logged_only(): void
    {
        $this->legacy_02_copy();
        $raised = 0;
        set_error_handler(static function () use (&$raised): bool {
            $raised++;

            return true;
        });
        try {
            \Tangible_DDD_Load_Diagnostics::at_winner_boot('0.7.0', dirname(__DIR__, 3), ['0.7.0' => '/n'], false, []);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, $raised);
        $this->assertCount(1, $this->logged);
        $this->assertStringStartsWith('[tangible-ddd] TANGIBLE_DDD_UNSUPPORTED_VERSION: ', $this->logged[0]);
        $this->assertStringContainsString('CorrelationContext', $this->logged[0]);
    }

    public function test_classes_declared_from_outside_the_winner_are_a_mixed_load(): void
    {
        $fqcn = 'TangibleDDD\\Fx\\Preloaded' . bin2hex(random_bytes(4));
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
        file_put_contents($this->scratch . '/pre.php', "<?php\nnamespace TangibleDDD\\Fx;\nclass {$short} {}\n");
        require $this->scratch . '/pre.php';

        $root = dirname(__DIR__, 3);
        $found = \Tangible_DDD_Load_Diagnostics::declared_elsewhere($root, [
            $fqcn,
            \TangibleDDD\Application\Process\LongProcess::class,
            self::class, // TangibleDDD\Tests\ is foreign
            'Tangible_DDD_Versions',
        ]);

        $this->assertSame([$fqcn => realpath($this->scratch . '/pre.php') ?: $this->scratch . '/pre.php'], array_map(
            static fn(string $f): string => realpath($f) ?: $f,
            $found
        ));

        \Tangible_DDD_Load_Diagnostics::at_winner_boot('0.7.0', $root, ['0.7.0' => $root], false);
        $mine = array_filter(
            \Tangible_DDD_Load_Diagnostics::findings('mixed-load'),
            static fn(array $f): bool => str_contains($f['message'], $fqcn . ' was loaded from')
        );
        $this->assertCount(1, $mine);
    }

    public function test_the_unit_suite_itself_boots_without_findings(): void
    {
        // Fixture classes the unit tests declare from scratch dirs are excluded:
        // they live in an `Fx` namespace segment under any TangibleDDD package
        // (TangibleDDD\Fx\, TangibleDDD\WordPress\Fx\ from WinnerAutoloaderTest,
        // TangibleDDD\Application\Fx\, TangibleDDD\Symfony\Fx\), and under
        // random order any of them may already be declared when this runs.
        $root = dirname(__DIR__, 3);
        $found = array_filter(
            \Tangible_DDD_Load_Diagnostics::declared_elsewhere($root),
            static fn(string $class): bool => !str_contains($class, '\\Fx\\'),
            ARRAY_FILTER_USE_KEY
        );
        $this->assertSame([], $found);
    }
}
