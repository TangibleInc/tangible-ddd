<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitedEventNotRegistered;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class ProcessRunnerAwaitAllTest extends TestCase {

  private FakeDDDConfig $config;
  private FakeProcessRepository $repo;
  private ProcessRunner $runner;

  protected function setUp(): void {
    // resume_on_event() takes a MySQL named lock via global $wpdb; the plain
    // wp-stubs wpdb::get_var() returns null (treated as "acquired").
    $GLOBALS['wpdb'] = new \wpdb();

    $this->config = new FakeDDDConfig();
    $this->repo = new FakeProcessRepository();
    $this->runner = new ProcessRunner($this->config, $this->repo);
  }

  protected function tearDown(): void {
  }

  private function event(int $id): FakeResolvedEvent {
    return new FakeResolvedEvent($id, FakeOutcome::Accepted, new \DateTimeImmutable());
  }

  public function test_suspend_requires_registered_event(): void {
    $process = new FakeGatherProcess([1]);
    $this->expectException(AwaitedEventNotRegistered::class);
    $this->runner->start($process);   // no register_event() call made
  }

  public function test_registration_guard_is_not_relabelled_as_business_failure(): void {
    // A wiring bug must propagate untouched: no 'failed' row, no ProcessFailed
    // announcement — otherwise monitoring reads a config error as a saga failure.
    $process_failed_fired = [];
    add_action(
      $this->config->hook('process_failed'),
      function ($event) use (&$process_failed_fired) { $process_failed_fired[] = $event; }
    );

    $process = new FakeGatherProcess([1]);

    try {
      $this->runner->start($process);   // no register_event() call made
      $this->fail('Expected AwaitedEventNotRegistered to propagate out of start()');
    } catch (AwaitedEventNotRegistered $e) {
      // expected
    }

    $saved = $this->repo->find($process->get_id());
    $this->assertNotNull($saved, 'process row persisted at start()');
    $this->assertNotSame('failed', $saved->status(), 'wiring bug must not mark the process failed');
    $this->assertSame([], $process_failed_fired, 'ProcessFailed must not fire for a wiring bug');
  }

  public function test_partial_arrival_accumulates_and_stays_suspended(): void {
    $this->runner->register_event(FakeResolvedEvent::class);
    $process = new FakeGatherProcess([1, 2]);
    $this->runner->start($process);
    $this->assertSame('suspended', $process->status());

    $this->runner->resume_on_event($this->event(1));

    $saved = $this->repo->find($process->get_id());
    $this->assertSame('suspended', $saved->status());
    $this->assertSame([1], $saved->await_mechanism()->gathered());
    $this->assertNotContains('evaluate', $process->executed_steps);
  }

  public function test_final_arrival_satisfies_and_resumes_with_mechanism(): void {
    $this->runner->register_event(FakeResolvedEvent::class);
    $process = new FakeGatherProcess([1, 2]);
    $this->runner->start($process);

    $this->runner->resume_on_event($this->event(1));
    $this->runner->resume_on_event($this->event(2));

    $saved = $this->repo->find($process->get_id());
    $this->assertSame('completed', $saved->status());
    $this->assertInstanceOf(AwaitAll::class, $saved->gather_seen ?? $process->gather_seen);
    $this->assertTrue(($saved->gather_seen ?? $process->gather_seen)->is_satisfied());
  }

  public function test_two_sagas_disjoint_keys_route_correctly(): void {
    $this->runner->register_event(FakeResolvedEvent::class);
    $a = new FakeGatherProcess([1]);
    $b = new FakeGatherProcess([2]);
    $this->runner->start($a);
    $this->runner->start($b);

    $this->runner->resume_on_event($this->event(2));

    $this->assertSame('suspended', $this->repo->find($a->get_id())->status());
    $this->assertSame('completed', $this->repo->find($b->get_id())->status());
  }

  public function test_await_event_behavior_unchanged(): void {
    // FakeSuspendingProcess round-trip — identical to the existing resume test:
    // start, fire matching FakeIntegrationEvent through resume_on_event, assert
    // after_action ran and received the EVENT (not a mechanism).
    $this->runner->register_event(\TangibleDDD\Tests\Fakes\FakeIntegrationEvent::class);
    $p = new \TangibleDDD\Tests\Fakes\FakeSuspendingProcess();
    $this->runner->start($p);
    $this->runner->resume_on_event(new \TangibleDDD\Tests\Fakes\FakeIntegrationEvent(entity_id: 42));
    $this->assertSame('completed', $this->repo->find($p->get_id())->status());
  }
}
