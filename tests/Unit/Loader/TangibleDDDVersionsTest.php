<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Tangible_DDD_Versions version-negotiation registry.
 *
 * The class lives in tangible-ddd.php (no namespace — global class).
 * Tests exercise the registry/selection logic in isolation (pure PHP, no WP).
 * We reset the singleton between tests by reflection so each test gets a clean slate.
 */
class TangibleDDDVersionsTest extends TestCase
{
    /**
     * Reset the singleton state between tests so they are truly independent.
     * We use reflection to null out the private static $instance and reinitialise
     * the object's internal arrays — simulating a fresh page load.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        parent::tearDown();
    }

    private function resetSingleton(): void
    {
        $ref = new \ReflectionClass(\Tangible_DDD_Versions::class);

        // Reset the static instance so instance() creates a new one.
        $instanceProp = $ref->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a callable that increments $counter and records the $path it received.
     */
    private function makeCallback(int &$counter, string &$receivedPath = ''): callable
    {
        return function (string $path) use (&$counter, &$receivedPath): void {
            $counter++;
            $receivedPath = $path;
        };
    }

    // ── (a) newest-wins ───────────────────────────────────────────────────────

    /**
     * @test
     */
    public function newest_wins_selects_highest_version_and_calls_only_its_callback(): void
    {
        $registry = \Tangible_DDD_Versions::instance();

        $calls020 = 0;
        $calls030 = 0;

        $registry->register('0.2.0', '/path/0.2.0', $this->makeCallback($calls020));
        $registry->register('0.3.0', '/path/0.3.0', $this->makeCallback($calls030));

        self::assertSame('0.3.0', $registry->latest(), 'latest() should return 0.3.0');

        $registry->initialize_latest();

        self::assertSame(0, $calls020, '0.2.0 callback must NOT be called');
        self::assertSame(1, $calls030, '0.3.0 callback must be called exactly once');

        $winner = $registry->winner();
        self::assertNotNull($winner);
        self::assertSame('0.3.0', $winner['version']);
        self::assertSame('/path/0.3.0', $winner['path']);
    }

    // ── (b) multi-load-safety ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function same_version_registered_twice_results_in_one_entry_and_one_init_call(): void
    {
        $registry = \Tangible_DDD_Versions::instance();
        $calls = 0;

        $registry->register('0.2.0-dev', '/original', $this->makeCallback($calls));
        // Second registration with same version — must be silently ignored.
        $registry->register('0.2.0-dev', '/duplicate', $this->makeCallback($calls));

        self::assertCount(1, $registry->all_registered(), 'Only one entry should exist');
        self::assertSame('/original', $registry->all_registered()['0.2.0-dev']);

        $registry->initialize_latest();

        self::assertSame(1, $calls, 'Callback must be called exactly once despite double registration');
    }

    /**
     * @test
     */
    public function initialize_latest_is_idempotent_calling_twice_runs_winner_once(): void
    {
        $registry = \Tangible_DDD_Versions::instance();
        $calls = 0;

        $registry->register('0.2.0-dev', '/path', $this->makeCallback($calls));

        $registry->initialize_latest();
        $registry->initialize_latest(); // second call must be a no-op

        self::assertSame(1, $calls, 'Callback must be called exactly once across two initialize_latest() calls');
    }

    // ── (c) min-version / unmet_minimums ─────────────────────────────────────

    /**
     * @test
     */
    public function consumer_with_min_required_above_winner_appears_in_unmet_minimums(): void
    {
        $registry = \Tangible_DDD_Versions::instance();
        $calls = 0;

        // Winner is 0.3.0; a consumer declares it needs >= 0.5.0.
        $registry->register('0.3.0', '/path/winner', $this->makeCallback($calls));
        $registry->require_version('some-consumer', '0.5.0');

        $registry->initialize_latest();

        self::assertSame('0.3.0', $registry->winner()['version'], 'Winner must still be 0.3.0');

        $unmet = $registry->unmet_minimums();
        self::assertArrayHasKey('some-consumer', $unmet, 'consumer needing 0.5.0 with winner 0.3.0 must be unmet');
        self::assertSame('0.5.0', $unmet['some-consumer']);

        // A requirement must NEVER pollute the copy pool / winner selection.
        self::assertArrayNotHasKey('some-consumer', $registry->all_registered());
        self::assertSame('0.3.0', $registry->latest(), 'requirement must not affect latest()');
    }

    /**
     * @test
     */
    public function consumer_with_min_required_at_or_below_winner_is_not_unmet(): void
    {
        $registry = \Tangible_DDD_Versions::instance();
        $calls = 0;

        $registry->register('0.3.0', '/path/winner', $this->makeCallback($calls));
        $registry->require_version('some-consumer', '0.2.0');

        $registry->initialize_latest();

        self::assertEmpty($registry->unmet_minimums(), 'min 0.2.0 with winner 0.3.0 must NOT be unmet');
    }

    // ── (d) single-copy — that copy wins and initializes ─────────────────────

    /**
     * @test
     */
    public function single_copy_wins_and_initializes_with_correct_path(): void
    {
        $registry = \Tangible_DDD_Versions::instance();
        $calls = 0;
        $receivedPath = '';

        $registry->register('0.2.0-dev', '/only/copy', $this->makeCallback($calls, $receivedPath));

        self::assertSame('0.2.0-dev', $registry->latest());
        self::assertNull($registry->winner(), 'winner() must be null before initialize_latest()');

        $registry->initialize_latest();

        self::assertSame(1, $calls, 'Single copy must be initialized');
        self::assertSame('/only/copy', $receivedPath, 'Path passed to callback must match registered path');
        self::assertSame('0.2.0-dev', $registry->winner()['version']);
        self::assertSame('/only/copy', $registry->winner()['path']);
    }

    // ── diagnostic helpers ────────────────────────────────────────────────────

    /**
     * @test
     */
    public function all_registered_returns_version_to_path_map(): void
    {
        $registry = \Tangible_DDD_Versions::instance();

        $registry->register('0.1.0', '/path/a', fn($p) => null);
        $registry->register('0.2.0', '/path/b', fn($p) => null);

        $all = $registry->all_registered();
        self::assertSame(['0.1.0' => '/path/a', '0.2.0' => '/path/b'], $all);
    }

    /**
     * @test
     */
    public function latest_returns_null_when_nothing_registered(): void
    {
        $registry = \Tangible_DDD_Versions::instance();
        self::assertNull($registry->latest());
    }

    /**
     * @test
     */
    public function is_initialized_reflects_state_correctly(): void
    {
        $registry = \Tangible_DDD_Versions::instance();
        self::assertFalse($registry->is_initialized());

        $registry->register('0.2.0-dev', '/path', fn($p) => null);
        $registry->initialize_latest();

        self::assertTrue($registry->is_initialized());
    }
}
