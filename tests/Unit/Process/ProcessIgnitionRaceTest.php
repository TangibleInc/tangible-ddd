<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Exceptions\LockingException;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeStartsOnProcess;

/**
 * Bug 2 (contract register section 6, schema-free variant): #[StartsOn]
 * ignition was check-then-insert with no lock (has_ignition SELECT, then a
 * separate insert in start()'s save), so two workers delivering the same
 * fact could both see "not ignited" and both insert. The fix holds a named
 * lock ddd_ign_ + md5(prefix|class|event_id) around re-check + insert.
 *
 * The race is modelled with a lock-aware wpdb: a second worker that arrives
 * inside the first worker's check/insert window takes the same named lock,
 * so while the first worker holds it the second one blocks (deferred until
 * RELEASE_LOCK); with no lock taken it runs straight through the window.
 *
 * Scenarios: process.ignition-race, delivery.double-delivery.process,
 * process.manual-start-in-drain.
 */
class ProcessIgnitionRaceTest extends TestCase {

  private FakeProcessRepository $repo;
  private ProcessRunner $runner;

  protected function setUp(): void {
    global $_test_actions;
    $_test_actions = [];
    Correlation::reset();
    $GLOBALS['wpdb'] = new \wpdb();

    $this->repo = new FakeProcessRepository();
    $this->runner = new ProcessRunner(new FakeDDDConfig(), $this->repo);
    $this->runner->register_start(FakeStartsOnProcess::class, FakeResolvedEvent::class);
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function envelope(string $event_id, int $request_id = 7): array {
    return [
      'request_id'  => $request_id,
      'outcome'     => FakeOutcome::Accepted->value,
      'resolved_at' => '2026-07-16T10:00:00+00:00',
      '__correlation_id' => 'igniting-corr',
      '__sequence'  => 1,
      '__event_id'  => $event_id,
    ];
  }

  private static function ignition_lock(string $event_id): string {
    return 'ddd_ign_' . md5('test|' . FakeStartsOnProcess::class . '|' . $event_id);
  }

  /** wpdb that models MySQL named locks held by this connection. */
  private function lock_aware_wpdb(): \wpdb {
    return new class extends \wpdb {
      /** @var array<string, int> name => re-entrant depth */
      public array $held = [];
      /** @var array<string, list<\Closure>> name => work blocked on it */
      public array $waiting = [];
      /** @var list<string> */
      public array $log = [];
      private array $last_args = [];

      public function prepare(string $query, ...$args): string {
        $this->last_args = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
        return $query;
      }

      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        $name = (string) ($this->last_args[0] ?? '');
        if ($query !== null && str_contains($query, 'GET_LOCK')) {
          $this->held[$name] = ($this->held[$name] ?? 0) + 1;
          $this->log[] = "GET $name";
          return '1';
        }
        if ($query !== null && str_contains($query, 'RELEASE_LOCK')) {
          $this->log[] = "RELEASE $name";
          if (--$this->held[$name] === 0) {
            unset($this->held[$name]);
            $blocked = $this->waiting[$name] ?? [];
            unset($this->waiting[$name]);
            foreach ($blocked as $work) {
              $work();
            }
          }
          return '1';
        }
        return null;
      }

      /** A second connection wanting $name: runs now if free, else when released. */
      public function contend(string $name, \Closure $work): void {
        if (isset($this->held[$name])) {
          $this->waiting[$name][] = $work;
          return;
        }
        $work();
      }
    };
  }

  public function test_second_worker_inside_the_check_insert_window_does_not_ignite_twice(): void {
    $GLOBALS['wpdb'] = $wpdb = $this->lock_aware_wpdb();
    $action = FakeResolvedEvent::integration_action();
    $payload = $this->envelope('evt_race');
    $lock = self::ignition_lock('evt_race');

    // Worker B arrives while worker A is between its has_ignition check and
    // its insert. B is a full second delivery through the same runner code.
    $this->repo->after_has_ignition = function () use ($wpdb, $action, $payload, $lock) {
      $this->repo->after_has_ignition = null; // one interloper
      $wpdb->contend($lock, static fn () => do_action($action, $payload));
    };

    do_action($action, $payload);   // worker A

    $this->assertCount(1, $this->repo->processes, 'exactly one ignited process per (class, event_id)');
    $process = array_values($this->repo->processes)[0];
    $this->assertSame(['react:7'], $process->executed_steps, 'the loser ran no step');
    $this->assertSame([], $wpdb->held, 'every lock released');
    $this->assertSame([], $wpdb->waiting, 'the blocked worker was released and ran');
  }

  public function test_check_and_insert_happen_inside_the_ignition_lock(): void {
    $GLOBALS['wpdb'] = $wpdb = $this->lock_aware_wpdb();
    $lock = self::ignition_lock('evt_locked');

    $held_at_check = null;
    $this->repo->after_has_ignition = function () use ($wpdb, $lock, &$held_at_check) {
      $held_at_check = isset($wpdb->held[$lock]);
    };

    do_action(FakeResolvedEvent::integration_action(), $this->envelope('evt_locked'));

    $this->assertTrue($held_at_check, 'the ignition re-check runs under ' . $lock);
    $process = array_values($this->repo->processes)[0];
    // The insert happened before the ignition lock was released, and the
    // first step ran afterwards under the per-process lock.
    $this->assertSame(
      ["GET $lock", "RELEASE $lock", 'GET ddd_process_' . $process->get_id(), 'RELEASE ddd_process_' . $process->get_id()],
      $wpdb->log
    );
  }

  public function test_ignition_lock_failure_persists_nothing(): void {
    $GLOBALS['wpdb'] = new class extends \wpdb {
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return $query !== null && str_contains($query, 'GET_LOCK') ? null : '1';
      }
    };

    $this->expectException(LockingException::class);
    try {
      do_action(FakeResolvedEvent::integration_action(), $this->envelope('evt_nolock'));
    } finally {
      $this->assertSame([], $this->repo->processes, 'no ignited row without the ignition lock');
    }
  }

  public function test_manual_start_inside_a_drain_is_never_deduped(): void {
    // process.manual-start-in-drain: a manual ->start() made inside a fact
    // drain stamps ignited_by_event_id but is not the #[StartsOn] path, so
    // two of them for the same fact are two processes.
    $runner = $this->runner;
    Correlation::within(Correlation::current()->for_fact('evt_manual'), static function () use ($runner) {
      $runner->start(new FakeStartsOnProcess(1));
      $runner->start(new FakeStartsOnProcess(2));
    });

    $this->assertCount(2, $this->repo->processes);
    foreach ($this->repo->processes as $p) {
      $this->assertSame('evt_manual', $p->ignited_by_event_id());
    }
    $this->assertSame([], $this->repo->ignition_checks, 'manual starts never consult the ignition dedup');
  }
}
