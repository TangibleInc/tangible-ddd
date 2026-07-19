<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeStartsOnProcess;

/**
 * #[StartsOn] — the reactive ignition door. A degenerate-in-reverse
 * IntegrationListener: same drain trigger, same unwrap/hydrate/stamp
 * preamble, same right to decline (from_event returns null) — but the
 * reaction persists and stays alive.
 */
class ProcessStartsOnTest extends TestCase {

  private FakeProcessRepository $repo;
  private ProcessRunner $runner;

  protected function setUp(): void {
    global $_test_actions;
    $_test_actions = [];
    $GLOBALS['wpdb'] = new \wpdb();

    $this->repo = new FakeProcessRepository();
    $this->runner = new ProcessRunner(new FakeDDDConfig(), $this->repo);
  }

  protected function tearDown(): void {
  }

  private function envelope(int $request_id, FakeOutcome $outcome, string $event_id): array {
    return [
      'request_id'  => $request_id,
      'outcome'     => $outcome->value,
      'resolved_at' => '2026-07-16T10:00:00+00:00',
      '__correlation_id' => 'igniting-corr',
      '__sequence'  => 1,
      '__event_id'  => $event_id,
    ];
  }

  public function test_register_start_lays_the_ignition_hook(): void {
    global $_test_actions;

    $this->runner->register_start(FakeStartsOnProcess::class, FakeResolvedEvent::class);

    $this->assertNotEmpty($_test_actions[FakeResolvedEvent::integration_action()] ?? []);
  }

  public function test_event_ignites_persists_and_runs_the_process(): void {
    $this->runner->register_start(FakeStartsOnProcess::class, FakeResolvedEvent::class);

    do_action(FakeResolvedEvent::integration_action(), $this->envelope(7, FakeOutcome::Accepted, 'evt_ign_1'));

    $started = array_values($this->repo->processes);
    $this->assertCount(1, $started);
    $process = $started[0];
    $this->assertInstanceOf(FakeStartsOnProcess::class, $process);
    $this->assertSame(['react:7'], $process->executed_steps);
    $this->assertSame('completed', $process->status());
    $this->assertSame('igniting-corr', $process->correlation_id(), 'saga inherits the fact\'s correlation');
    $this->assertSame('evt_ign_1', $process->ignited_by_event_id(), 'ignition edge recorded on the row');
  }

  public function test_from_event_null_declines_ignition(): void {
    $this->runner->register_start(FakeStartsOnProcess::class, FakeResolvedEvent::class);

    do_action(FakeResolvedEvent::integration_action(), $this->envelope(7, FakeOutcome::Rejected, 'evt_ign_2'));

    $this->assertSame([], $this->repo->processes, 'declined ignition must persist nothing');
  }

  public function test_replayed_event_does_not_ignite_twice(): void {
    $this->runner->register_start(FakeStartsOnProcess::class, FakeResolvedEvent::class);
    $payload = $this->envelope(7, FakeOutcome::Accepted, 'evt_ign_3');

    do_action(FakeResolvedEvent::integration_action(), $payload);
    do_action(FakeResolvedEvent::integration_action(), $payload); // replay / redelivery

    $this->assertCount(1, $this->repo->processes, 'journey event_id dedups ignition');
  }

  public function test_register_start_requires_from_event(): void {
    $this->expectException(\InvalidArgumentException::class);
    // FakeThreeStepProcess has no from_event()
    $this->runner->register_start(\TangibleDDD\Tests\Fakes\FakeThreeStepProcess::class, FakeResolvedEvent::class);
  }
}
