<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Console\Ops\DlqListCommand;
use TangibleDDD\Symfony\Console\Ops\DlqReplayCommand;
use TangibleDDD\Symfony\Console\Ops\DlqRetryCommand;
use TangibleDDD\Symfony\Console\Ops\PauseCommand;
use TangibleDDD\Symfony\Console\Ops\ResumeCommand;
use TangibleDDD\Symfony\Console\Ops\StrandedCommand;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Persistence\DbalOutboxAdministration;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\OrderProcess;

/**
 * `ddd:ops:*` (register 3.10, 5.1): the operator surface over the relay DLQ,
 * stranded processes / exhausted wakeups and relay pauses, on Postgres 16.
 */
final class OpsCommandsTest extends PostgresTestCase {

  private FrozenClock $clock;
  private DbalPostgresOutboxStore $outbox;
  private DbalOutboxAdministration $admin;
  private DbalRelayPauseStore $pauses;
  private DbalProcessStore $processes;
  private DbalWakeupScheduler $wakeups;
  private DbalTransactionBoundary $boundary;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->pauses = new DbalRelayPauseStore($this->db);
    $this->outbox = new DbalPostgresOutboxStore($this->db, $this->pauses);
    $this->admin = new DbalOutboxAdministration($this->db, $this->clock);
    $this->processes = new DbalProcessStore($this->db, $this->clock);
    $this->wakeups = new DbalWakeupScheduler($this->db);
    $this->boundary = new DbalTransactionBoundary($this->db, NestedPolicy::Reject);
  }

  private function deadLetter(string $id): int {
    $this->outbox->appendFact(new OutboxRecord($id, 'widget_registered', 'acme_integration_widget_registered', 'c', 1, null, ['id' => $id], $this->clock->now()), 'App\\W');
    [$claim] = $this->outbox->claim(1, $this->clock->now(), 60);
    $this->outbox->deadLetter($claim, "boom $id");
    return (int) $this->db->fetchOne('SELECT id FROM ddd_dlq WHERE event_id = ?', [$id]);
  }

  private function outboxStatus(string $id): string {
    return (string) $this->db->fetchOne('SELECT status FROM ddd_outbox WHERE event_id = ?', [$id]);
  }

  private function stranded(): StrandedCommand {
    $lock = new ReentrantProcessLock(new PostgresAdvisoryProcessLock($this->db));
    return new StrandedCommand($this->processes, $this->wakeups, $this->boundary, $lock, $this->clock, 'acme');
  }

  public function test_dlq_list_shows_dead_letters_with_their_error(): void {
    $this->deadLetter('e-1');
    $this->deadLetter('e-2');

    $t = new CommandTester(new DlqListCommand($this->admin));
    self::assertSame(Command::SUCCESS, $t->execute(['--limit' => '1']));

    self::assertStringContainsString('e-1', $t->getDisplay());
    self::assertStringContainsString('boom e-1', $t->getDisplay());
    self::assertStringNotContainsString('e-2', $t->getDisplay());
    self::assertStringContainsString('--after=', $t->getDisplay(), 'it tells the operator how to page');
  }

  public function test_dlq_replay_keeps_the_event_id_and_removes_the_dlq_row(): void {
    $dlq = $this->deadLetter('e-1');

    $t = new CommandTester(new DlqReplayCommand($this->admin));
    self::assertSame(Command::SUCCESS, $t->execute(['dlq-id' => [(string) $dlq]]));

    self::assertSame('pending', $this->outboxStatus('e-1'));
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_dlq'));
  }

  public function test_dlq_replay_of_an_unknown_id_fails_without_touching_the_others(): void {
    $dlq = $this->deadLetter('e-1');

    $t = new CommandTester(new DlqReplayCommand($this->admin));
    self::assertSame(Command::FAILURE, $t->execute(['dlq-id' => ['999', (string) $dlq]]));

    self::assertStringContainsString('999', $t->getDisplay());
    self::assertSame('pending', $this->outboxStatus('e-1'), 'the valid id is still replayed');
  }

  public function test_dlq_retry_resets_the_row_and_removes_its_dlq_entry(): void {
    $this->deadLetter('e-1');

    $t = new CommandTester(new DlqRetryCommand($this->admin));
    self::assertSame(Command::SUCCESS, $t->execute(['event-id' => ['e-1']]));

    self::assertSame('pending', $this->outboxStatus('e-1'));
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_dlq'), 'retry removes the DLQ entry (sfc-5)');
  }

  public function test_dlq_retry_of_an_accepted_row_needs_force(): void {
    $this->outbox->appendFact(new OutboxRecord('e-1', 'w', 'a', 'c', 1, null, [], $this->clock->now()), 'App\\W');
    [$claim] = $this->outbox->claim(1, $this->clock->now(), 60);
    $this->outbox->accept($claim, 'ref');

    $t = new CommandTester(new DlqRetryCommand($this->admin));
    self::assertSame(Command::FAILURE, $t->execute(['event-id' => ['e-1']]));
    self::assertStringContainsString('force', $t->getDisplay());

    self::assertSame(Command::SUCCESS, $t->execute(['event-id' => ['e-1'], '--force' => true]));
    self::assertSame('pending', $this->outboxStatus('e-1'));
  }

  public function test_pause_and_resume_hold_and_release_a_selector(): void {
    $pause = new CommandTester(new PauseCommand($this->pauses, $this->clock));
    self::assertSame(Command::SUCCESS, $pause->execute(['selector' => 'widget_*', '--holder' => 'deploy']));
    self::assertTrue($this->pauses->isPaused('widget_registered', $this->clock->now()));
    self::assertStringContainsString('widget_*', $pause->getDisplay());

    $resume = new CommandTester(new ResumeCommand($this->pauses));
    self::assertSame(Command::SUCCESS, $resume->execute(['selector' => 'widget_*', '--holder' => 'deploy']));
    self::assertFalse($this->pauses->isPaused('widget_registered', $this->clock->now()));
  }

  public function test_pause_until_expires_by_itself(): void {
    $pause = new CommandTester(new PauseCommand($this->pauses, $this->clock));
    $pause->execute(['selector' => '*', '--for' => '600']);

    self::assertTrue($this->pauses->isPaused('anything', $this->clock->now()));
    self::assertFalse($this->pauses->isPaused('anything', $this->clock->now()->modify('+601 seconds')));
  }

  public function test_resume_without_a_selector_releases_every_hold_of_the_holder(): void {
    $this->pauses->hold('ops', 'a', null);
    $this->pauses->hold('ops', 'b', null);
    $this->pauses->hold('deploy', 'c', null);

    (new CommandTester(new ResumeCommand($this->pauses)))->execute([]);

    self::assertFalse($this->pauses->isPaused('a', $this->clock->now()));
    self::assertFalse($this->pauses->isPaused('b', $this->clock->now()));
    self::assertTrue($this->pauses->isPaused('c', $this->clock->now()), 'another holder keeps its pause');
  }

  public function test_stranded_lists_stranded_processes_and_exhausted_intents(): void {
    $running = OrderProcess::started(1);
    $this->processes->insert($running);
    $this->boundary->run(fn () => $this->wakeups->schedule(WakeupIntent::timeout('acme', 77, 2, $this->clock->now())));
    [$w] = $this->wakeups->claimDue($this->clock->now(), 10, 60);
    $this->wakeups->exhaust($w, 'lock busy x10');
    $this->clock->advance('+16 minutes');

    $t = new CommandTester($this->stranded());
    self::assertSame(Command::SUCCESS, $t->execute([]));

    $out = $t->getDisplay();
    self::assertStringContainsString((string) $running->get_id(), $out);
    self::assertStringContainsString('running', $out);
    self::assertStringContainsString('timeout:77:2', $out);
    self::assertStringContainsString('lock busy x10', $out);
  }

  public function test_stranded_rearm_puts_an_exhausted_intent_back(): void {
    $this->boundary->run(fn () => $this->wakeups->schedule(WakeupIntent::timeout('acme', 77, 2, $this->clock->now())));
    [$w] = $this->wakeups->claimDue($this->clock->now(), 10, 60);
    $this->wakeups->exhaust($w, 'boom');

    $t = new CommandTester($this->stranded());
    self::assertSame(Command::SUCCESS, $t->execute(['--rearm' => ['timeout:77:2']]));

    self::assertCount(1, $this->wakeups->claimDue($this->clock->now(), 10, 60));
  }

  public function test_stranded_resume_mints_a_continue_intent_for_the_process(): void {
    $p = OrderProcess::started(1);
    $id = $this->processes->insert($p);

    $t = new CommandTester($this->stranded());
    self::assertSame(Command::SUCCESS, $t->execute(['--resume' => [(string) $id]]));

    [$claimed] = $this->wakeups->claimDue($this->clock->now(), 10, 60);
    self::assertSame("continue:$id:0", $claimed->intent->idempotencyKey);
    self::assertSame('running', $claimed->intent->expectedStatus, 'a running row is resumed at its current step');
  }

  public function test_stranded_fail_marks_the_process_failed_under_its_lock(): void {
    $p = OrderProcess::started(1);
    $id = $this->processes->insert($p);

    $t = new CommandTester($this->stranded());
    self::assertSame(Command::SUCCESS, $t->execute(['--fail' => [(string) $id], '--reason' => 'operator: payment provider gone']));

    $row = $this->db->fetchAssociative('SELECT status, last_error, version FROM ddd_processes WHERE id = ?', [$id]);
    self::assertSame('failed', $row['status']);
    self::assertSame('operator: payment provider gone', $row['last_error']);
    self::assertSame(2, (int) $row['version']);
  }

  public function test_stranded_fail_refuses_while_another_session_holds_the_process_lock(): void {
    $id = $this->processes->insert(OrderProcess::started(1));
    $other = new PostgresAdvisoryProcessLock($this->secondConnection());
    $other->acquire(new \TangibleDDD\Runtime\Lock\LockKey('acme', '', $id), 1.0);

    $t = new CommandTester($this->stranded());
    self::assertSame(Command::FAILURE, $t->execute(['--fail' => [(string) $id]]));

    self::assertStringContainsString('lock', $t->getDisplay());
    self::assertSame('running', $this->db->fetchOne('SELECT status FROM ddd_processes WHERE id = ?', [$id]));
  }
}
