<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
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
}
