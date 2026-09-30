<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Exceptions\LockingException;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;

/**
 * Bug 1 (contract register section 6): only a definite GET_LOCK acquisition
 * ('1') enters the per-process critical section. NULL (query error, killed
 * connection) and '0' (timeout / contended) both mean "not acquired": no
 * step runs, nothing is saved, no RELEASE_LOCK is issued for a lock never
 * held, and LockingException propagates so Action Scheduler records the
 * failed action.
 *
 * Scenarios: lock.acquire-error, lock.contention (hotfix half: no "later
 * succeeds"), lock.reentrant-balance.
 */
class ProcessLockAcquisitionTest extends TestCase {

  private FakeProcessRepository $repo;
  private ProcessRunner $runner;

  protected function setUp(): void {
    $GLOBALS['wpdb'] = new \wpdb();
    $this->repo = new FakeProcessRepository();
    $this->runner = new ProcessRunner(new FakeDDDConfig(), $this->repo);
    $this->runner->register_event(FakeResolvedEvent::class);
  }

  /**
   * wpdb whose GET_LOCK answers $result (null = error, '0' = timeout) and
   * which records every lock query, in order.
   */
  private function lock_wpdb(?string $result, string $error = ''): \wpdb {
    return new class($result, $error) extends \wpdb {
      /** @var list<string> */
      public array $lock_calls = [];

      public function __construct(private ?string $result, private string $error) {}

      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        if ($query !== null && str_contains($query, 'GET_LOCK')) {
          $this->lock_calls[] = 'GET';
          $this->last_error = $this->error;
          return $this->result;
        }
        if ($query !== null && str_contains($query, 'RELEASE_LOCK')) {
          $this->lock_calls[] = 'RELEASE';
          return '1';
        }
        return null;
      }
    };
  }

  /** @return array<string, array{0: ?string, 1: string}> */
  public static function not_acquired(): array {
    return [
      'NULL (query error)'  => [null, 'Lost connection to MySQL server during query'],
      'NULL (no error set)' => [null, ''],
      '0 (timeout)'         => ['0', ''],
    ];
  }

  #[DataProvider('not_acquired')]
  public function test_start_does_not_run_steps_when_the_lock_is_not_acquired(?string $result, string $error): void {
    $GLOBALS['wpdb'] = $wpdb = $this->lock_wpdb($result, $error);
    $p = new FakeThreeStepProcess();

    try {
      $this->runner->start($p);
      $this->fail('start() must throw when the process lock is not acquired');
    } catch (LockingException $e) {
      $this->assertStringContainsString('ddd_process_' . $p->get_id(), $e->getMessage());
      if ($error !== '') {
        $this->assertStringContainsString($error, $e->getMessage(), 'the query error is the reason');
      }
    }

    $this->assertSame([], $p->executed_steps, 'no step runs unlocked');
    $this->assertSame(['GET'], $wpdb->lock_calls, 'a lock never held is never released');
  }

  #[DataProvider('not_acquired')]
  public function test_resume_does_not_mutate_the_process_when_the_lock_is_not_acquired(?string $result, string $error): void {
    $p = new FakeGatherProcess([1]);
    $this->runner->start($p);   // stub lock: acquired; suspends on the gather
    $this->assertSame('suspended', $p->status());
    $saves = $this->repo->save_count;

    $GLOBALS['wpdb'] = $this->lock_wpdb($result, $error);

    try {
      $this->runner->resume_on_event(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));
      $this->fail('resume_on_event() must throw when the process lock is not acquired');
    } catch (LockingException) {
    }

    $this->assertSame(['dispatch'], $p->executed_steps, 'the resumed step did not run');
    $this->assertSame('suspended', $p->status());
    $this->assertSame($saves, $this->repo->save_count, 'nothing saved without the lock');
  }

  #[DataProvider('not_acquired')]
  public function test_continuation_does_not_run_when_the_lock_is_not_acquired(?string $result, string $error): void {
    $p = new FakeGatherProcess([1]);
    $this->runner->start($p);
    $p->advance(status: 'scheduled', payload: $p->payload());
    $saves = $this->repo->save_count;

    $GLOBALS['wpdb'] = $this->lock_wpdb($result, $error);

    try {
      $this->runner->continue_scheduled($p->get_id());
      $this->fail('continue_scheduled() must throw when the process lock is not acquired');
    } catch (LockingException) {
    }

    $this->assertSame('scheduled', $p->status());
    $this->assertSame($saves, $this->repo->save_count);
  }

  #[DataProvider('not_acquired')]
  public function test_timeout_does_not_run_when_the_lock_is_not_acquired(?string $result, string $error): void {
    $p = new FakeGatherProcess([1]);
    $this->runner->start($p);
    $saves = $this->repo->save_count;

    $GLOBALS['wpdb'] = $this->lock_wpdb($result, $error);

    try {
      $this->runner->handle_timeout($p->get_id(), $p->current_step_index());
      $this->fail('handle_timeout() must throw when the process lock is not acquired');
    } catch (LockingException) {
    }

    $this->assertSame(['dispatch'], $p->executed_steps);
    $this->assertSame('suspended', $p->status());
    $this->assertSame($saves, $this->repo->save_count);
  }

  public function test_every_acquisition_is_released_even_when_a_step_throws(): void {
    // lock.reentrant-balance: handle_timeout takes the lock twice (outer
    // guard + sealed bracket); each GET is balanced by its own RELEASE.
    $GLOBALS['wpdb'] = $wpdb = $this->lock_wpdb('1');
    $p = new \TangibleDDD\Tests\Fakes\FakeGatherFailProcess([1]);
    $this->runner->start($p);
    $this->runner->handle_timeout($p->get_id(), $p->current_step_index());

    $gets = count(array_filter($wpdb->lock_calls, fn($c) => $c === 'GET'));
    $releases = count(array_filter($wpdb->lock_calls, fn($c) => $c === 'RELEASE'));
    $this->assertGreaterThan(0, $gets);
    $this->assertSame($gets, $releases);

    // A throwing step inside the bracket still releases.
    $GLOBALS['wpdb'] = $wpdb = $this->lock_wpdb('1');
    try {
      $this->runner->start(new \TangibleDDD\Tests\Fakes\FakeFailingProcess());
    } catch (\Throwable) {
    }
    $this->assertSame(['GET', 'RELEASE'], $wpdb->lock_calls);
  }
}
