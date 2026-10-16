<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Exceptions\LockingException;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeStartsOnProcess;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

/**
 * Bugs 1 and 2 against a real MySQL named-lock server: the real $wpdb
 * returns GET_LOCK results as strings, and a lock held by another
 * connection times out ('0') rather than being ignored.
 *
 * Scenarios: lock.contention (hotfix half: throws, no "later succeeds"),
 * process.ignition-race (the ignition lock is a real server-side lock).
 *
 * Process state lives in FakeProcessRepository; only the locks are real.
 * Each contended case waits the runner's 5 s GET_LOCK timeout.
 */
final class ProcessLockMysqlTest extends TestCase
{
    private \wpdb $other;
    private FakeProcessRepository $repo;
    private ProcessRunner $runner;

    protected function setUp(): void
    {
        global $_test_actions;
        $_test_actions = [];

        // A second connection: MySQL named locks are per connection.
        $this->other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->repo = new FakeProcessRepository();
        $this->runner = new ProcessRunner(new FakeDDDConfig(), $this->repo);
    }

    protected function tearDown(): void
    {
        $this->other->query('SELECT RELEASE_ALL_LOCKS()');
        $this->other->close();
    }

    public function test_an_uncontended_real_lock_is_acquired_and_released(): void
    {
        global $wpdb;
        $p = new FakeThreeStepProcess();
        $this->runner->start($p);

        $this->assertSame(['initialize', 'process_data', 'finalize'], $p->executed_steps);
        $this->assertSame('completed', $p->status());
        $this->assertSame(
            '1',
            (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', 'ddd_process_' . $p->get_id())),
            'the lock was released'
        );
    }

    public function test_a_lock_held_by_another_connection_fails_the_wake_without_running_it(): void
    {
        // FakeProcessRepository ids start at 1: hold that process's lock.
        $this->assertSame('1', (string) $this->other->get_var("SELECT GET_LOCK('ddd_process_1', 0)"));

        $p = new FakeThreeStepProcess();
        try {
            $this->runner->start($p);
            $this->fail('a contended lock must throw LockingException');
        } catch (LockingException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $this->assertSame(1, $p->get_id());
        $this->assertSame([], $p->executed_steps, 'no step ran while another connection held the lock');
    }

    public function test_a_held_ignition_lock_blocks_a_second_ignition_of_the_same_fact(): void
    {
        $this->runner->register_start(FakeStartsOnProcess::class, FakeResolvedEvent::class);
        $name = 'ddd_ign_' . md5('test|' . FakeStartsOnProcess::class . '|evt_mysql_race');
        $this->assertSame('1', (string) $this->other->get_var($this->other->prepare('SELECT GET_LOCK(%s, 0)', $name)));

        $this->expectException(LockingException::class);
        try {
            do_action(FakeResolvedEvent::integration_action(), [
                'request_id'  => 7,
                'outcome'     => FakeOutcome::Accepted->value,
                'resolved_at' => '2026-07-16T10:00:00+00:00',
                '__correlation_id' => 'igniting-corr',
                '__sequence'  => 1,
                '__event_id'  => 'evt_mysql_race',
            ]);
        } finally {
            $this->assertSame([], $this->repo->processes, 'no process inserted while another worker holds the ignition lock');
        }
    }
}
