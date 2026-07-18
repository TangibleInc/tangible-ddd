<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeGatherFailCompensatingProcess;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakePayload;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AwaitTimeoutTest extends TestCase {

  private FakeDDDConfig $config;
  private FakeProcessRepository $repo;
  private ProcessRunner $runner;

  protected function setUp(): void {
    CorrelationContext::reset();
    CorrelationContext::init('test-corr');

    // resume_on_event() takes a MySQL named lock via global $wpdb; the plain
    // wp-stubs wpdb::get_var() returns null (treated as "acquired").
    $GLOBALS['wpdb'] = new \wpdb();

    $this->config = new FakeDDDConfig();
    $this->repo = new FakeProcessRepository();
    $this->runner = new ProcessRunner($this->config, $this->repo);
    $this->runner->register_event(FakeResolvedEvent::class);
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_suspend_schedules_the_alarm(): void {
    global $_test_scheduled_actions;
    $_test_scheduled_actions = [];
    $p = new FakeGatherProcess([1]);
    $this->runner->start($p);

    $alarms = array_filter($_test_scheduled_actions, fn($a) => str_contains($a['hook'], 'await_timeout'));
    $this->assertCount(1, $alarms);
    $alarm = array_values($alarms)[0];
    $this->assertSame($p->get_id(), $alarm['args']['process_id']);
    $this->assertSame($p->current_step_index(), $alarm['args']['step_index']);
  }

  public function test_stale_alarm_is_a_noop(): void {
    $p = new FakeGatherProcess([1]);
    $this->runner->start($p);
    $suspended_index = $p->current_step_index();
    $this->runner->resume_on_event(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));
    $this->assertSame('completed', $this->repo->find($p->get_id())->status());

    $this->runner->handle_timeout($p->get_id(), $suspended_index);   // fires late
    $this->assertSame('completed', $this->repo->find($p->get_id())->status());   // unchanged
  }

  public function test_proceed_policy_resumes_with_partial(): void {
    $p = new FakeGatherProcess([1, 2]);   // on_timeout: PROCEED (fixture default)
    $this->runner->start($p);
    $this->runner->resume_on_event(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));

    $this->runner->handle_timeout($p->get_id(), $this->repo->find($p->get_id())->current_step_index());

    $saved = $this->repo->find($p->get_id());
    $this->assertSame('completed', $saved->status());
    $gather = $p->gather_seen;
    $this->assertInstanceOf(AwaitAll::class, $gather);
    $this->assertFalse($gather->is_satisfied());
    $this->assertSame([2], $gather->missing());
  }

  public function test_fail_policy_compensates(): void {
    // FakeGatherFailProcess: identical to FakeGatherProcess but on_timeout: TIMEOUT_FAIL
    // (create in tests/Fakes as a subclass overriding dispatch()).
    $p = new \TangibleDDD\Tests\Fakes\FakeGatherFailProcess([1]);
    $this->runner->start($p);

    $this->runner->handle_timeout($p->get_id(), $this->repo->find($p->get_id())->current_step_index());

    $this->assertSame('failed', $this->repo->find($p->get_id())->status());
  }

  public function test_fail_policy_runs_registered_compensations(): void {
    // A completed step precedes the await, so undo_index >= 0 and the
    // compensation loop actually iterates (not just finish_compensation()).
    $p = new FakeGatherFailCompensatingProcess([1]);
    $this->runner->start($p);
    $this->assertSame(['reserve', 'dispatch'], $p->executed_steps);
    $this->assertSame('suspended', $this->repo->find($p->get_id())->status());

    $this->runner->handle_timeout($p->get_id(), $this->repo->find($p->get_id())->current_step_index());

    $this->assertSame(['reserve', 'dispatch', 'undo_reserve'], $p->executed_steps);
    $this->assertInstanceOf(FakePayload::class, $p->checkpoint_seen);
    $this->assertSame('reserve_checkpoint', $p->checkpoint_seen->data);
    $this->assertSame('failed', $this->repo->find($p->get_id())->status());
  }

  public function test_handle_timeout_serializes_via_process_lock(): void {
    // handle_timeout mutates the same suspended row as resume_on_event; it
    // must take the per-process MySQL named lock so a late final event and
    // the alarm can't interleave (last-writer-wins corruption).
    $spy = new class extends \wpdb {
      public array $lock_names = [];
      public function prepare(string $query, ...$args): string {
        if (str_contains($query, 'GET_LOCK')) {
          $this->lock_names[] = $args[0] ?? null;
        }
        return $query;
      }
    };
    $GLOBALS['wpdb'] = $spy;

    $p = new \TangibleDDD\Tests\Fakes\FakeGatherFailProcess([1]);
    $this->runner->start($p);

    $this->runner->handle_timeout($p->get_id(), $this->repo->find($p->get_id())->current_step_index());

    // Two acquisitions of the SAME name: handle_timeout's outer lock (the
    // find + stale-guards must run inside it) and the sealed bracket's
    // re-entrant inner one. GET_LOCK is re-entrant per connection; what
    // matters is that every acquisition names this process's lock.
    $this->assertNotEmpty($spy->lock_names);
    $this->assertSame(
      ['ddd_process_' . $p->get_id()],
      array_values(array_unique($spy->lock_names)),
      'every lock acquisition must target this process'
    );
    $this->assertSame('failed', $this->repo->find($p->get_id())->status());
  }

  public function test_handle_timeout_arms_the_resource_governor(): void {
    // handle_timeout is an AS-action entry point like continue_scheduled: it
    // must set started_at, or time_exceeded() stays false (started_at null)
    // and the whole compensation cascade runs ungoverned. With the budget
    // forced to zero, the governor must reschedule after the first
    // compensation instead of finishing the cascade in-request.
    $p = new FakeGatherFailCompensatingProcess([1]);
    $this->runner->start($p);   // normal budget: runs to suspension

    // Simulate a fresh AS-action entry (new request → new runner → alarm
    // callback), where started_at has never been set by run().
    $started_at = new \ReflectionProperty($this->runner, 'started_at');
    $started_at->setValue($this->runner, null);

    // Zero the budget only for the alarm handling.
    $governor = new \ReflectionProperty($this->runner, 'max_execution_seconds');
    $governor->setValue($this->runner, 0);

    global $_test_scheduled_actions;
    $_test_scheduled_actions = [];

    $this->runner->handle_timeout($p->get_id(), $this->repo->find($p->get_id())->current_step_index());

    $this->assertContains('undo_reserve', $p->executed_steps);
    $this->assertSame('scheduled', $this->repo->find($p->get_id())->status());
    $continuations = array_filter($_test_scheduled_actions, fn($a) => str_contains($a['hook'], 'process_continue'));
    $this->assertCount(1, $continuations);
  }
}
