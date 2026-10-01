<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\ProcessLockUnavailable;
use TangibleDDD\Conformance\Fixtures\Process\GatherPartsProcess;
use TangibleDDD\Conformance\Fixtures\Process\MakeWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * W3C-R1 (wave3-core-change-requests.md, wave3-notes.md): the FINAL wp
 * IWakeupScheduler, WpdbWakeupScheduler, accepts WakeKind::ResumeRetry. It
 * stores the intent row and projects it, at schedule time, to the
 * DDD-owned Action Scheduler hook `{prefix}_ddd_wakeup` with
 * ['key' => idempotency key]; that hook's callback (hooks.php →
 * WpWakeBracket::resumeRetry) calls ProcessRunner::wake() with the STORED
 * intent, so a `running` retry is checked against the row version its key
 * carries. Not a catalogue id (check-due ignores these methods);
 * lock.contention and process.crash-mid-step exercise the same path inside
 * the shared scenarios.
 */
#[Group('wp')]
#[Group('w3c-r1')]
final class WpResumeRetryConformance extends ProcessScenarioCase {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }

  private function wp(): WpHostFixture {
    self::assertInstanceOf(WpHostFixture::class, $this->host);
    return $this->host;
  }

  #[TestDox('a ResumeRetry intent is stored and projected to {prefix}_ddd_wakeup [key]; running that action wakes the runner with the stored intent')]
  public function test_resume_retry_is_projected_to_the_ddd_wakeup_hook_and_wakes_the_runner(): void {
    $processes = $this->processes();
    $processes->wireProcesses([], [PartArrived::class]);
    $id = $this->start(new GatherPartsProcess('w-1', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    $this->host->advanceClock(GatherPartsProcess::TIMEOUT_SECONDS + 1);
    $now = $this->host->clock()->now();
    $retry = WakeupIntent::resumeRetry($processes->processConsumer(), $id, 1, 'suspended', $this->row($id)->version, $now, 'r1');

    $this->host->boundary()->run(static fn () => $processes->wakeups()->schedule($retry));

    $row = $this->intentRow($retry->idempotencyKey);
    self::assertSame(['resume_retry', 'pending', $this->hook('ddd_wakeup')], [$row['kind'], $row['status'], $row['hook']]);
    self::assertSame(['key' => $retry->idempotencyKey], json_decode((string) $row['args'], true));
    $actions = $this->pendingActions('ddd_wakeup', ['key' => $retry->idempotencyKey]);
    self::assertCount(1, $actions, 'projected at schedule time, in the same transaction');
    self::assertSame((int) $row['as_action_id'], $actions[0]);
    self::assertEquals([$retry], array_values(array_filter($processes->pendingWakeups(), static fn (WakeupIntent $i) => $i->kind === WakeKind::ResumeRetry)), 'read back as the stored intent');

    \ActionScheduler::runner()->process_action($actions[0], 'w3c-r1');

    self::assertSame(1, ProcessJournal::runs('undo_prepare'), 'the stored intent re-ran the timeout through ProcessRunner::wake()');
    self::assertSame('failed', $this->row($id)->status);
    self::assertSame('done', $this->intentRow($retry->idempotencyKey)['status']);
  }

  #[TestDox('a timeout fired on its legacy hook while another session holds the lock is re-queued as a ResumeRetry on {prefix}_ddd_wakeup, which later succeeds')]
  public function test_a_contended_timeout_is_re_queued_as_a_resume_retry_and_later_succeeds(): void {
    $processes = $this->processes();
    $processes->wireProcesses([], [PartArrived::class]);
    $id = $this->start(new GatherPartsProcess('w-1', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    $this->host->advanceClock(GatherPartsProcess::TIMEOUT_SECONDS + 1);
    $timeout = $this->pendingActions('await_timeout', ['process_id' => $id, 'step_index' => 1]);
    self::assertCount(1, $timeout);
    $processes->holdProcessLockElsewhere($id);

    \ActionScheduler::runner()->process_action($timeout[0], 'w3c-r1');

    $retries = $this->intents($id, WakeKind::ResumeRetry);
    self::assertCount(1, $retries, 'the contended wake was re-queued (core: ResumeRetry on LockNotAcquired)');
    self::assertSame(['suspended', 1], [$retries[0]->expectedStatus, $retries[0]->stepIndex]);
    $action = $this->pendingActions('ddd_wakeup', ['key' => $retries[0]->idempotencyKey]);
    self::assertCount(1, $action, 'on the DDD-owned hook');
    self::assertSame('suspended', $this->row($id)->status, 'nothing ran without the lock');

    $processes->releaseProcessLockElsewhere($id);
    $this->host->advanceClock(self::PAST_WAKE_BACKOFF);
    \ActionScheduler::runner()->process_action($action[0], 'w3c-r1');

    self::assertSame(1, ProcessJournal::runs('undo_prepare'), 'later succeeds, once');
    self::assertSame('failed', $this->row($id)->status);
    self::assertSame('done', $this->intentRow($retries[0]->idempotencyKey)['status']);
  }

  #[TestDox('an in-band start that cannot lock is re-queued as a running ResumeRetry at the row version; the hook runs the first step once')]
  public function test_a_running_resume_retry_runs_the_first_step_at_the_stored_version(): void {
    $processes = $this->processes();
    // The table is fresh: the process about to be inserted is #1.
    $processes->beforeNextProcessLockAcquire(static fn () => $processes->holdProcessLockElsewhere(1));

    $thrown = self::catchThrowable(fn () => $processes->worker()->processRunner()->start(new MakeWidgetProcess('w-1')));

    self::assertInstanceOf(ProcessLockUnavailable::class, $thrown);
    self::assertSame('running', $this->row(1)->status);
    self::assertSame([], ProcessJournal::$steps, 'no step ran without the lock');
    $retries = $this->intents(1, WakeKind::ResumeRetry);
    self::assertCount(1, $retries);
    self::assertSame('running', $retries[0]->expectedStatus);
    self::assertSame($this->row(1)->version, $retries[0]->retryVersion(), 'the key carries the row version');

    $processes->releaseProcessLockElsewhere(1);
    $this->host->advanceClock(self::PAST_WAKE_BACKOFF);
    \ActionScheduler::runner()->process_action($this->pendingActions('ddd_wakeup', ['key' => $retries[0]->idempotencyKey])[0], 'w3c-r1');

    self::assertSame(['make', 'finish'], ProcessJournal::$steps, 'the first step ran once, then the process finished');
    self::assertSame('completed', $this->row(1)->status);
    self::assertSame([], $this->intents(1));
  }

  #[TestDox('scheduling a finished ResumeRetry key again re-arms it with its own step index (null stays null) and expected status')]
  public function test_re_arming_a_resume_retry_key_keeps_its_step_index_and_expected_status(): void {
    $processes = $this->processes();
    $id = $this->start(new MakeWidgetProcess('w-1'));
    $now = $this->host->clock()->now();
    $retry = WakeupIntent::resumeRetry($processes->processConsumer(), $id, null, 'running', 1, $now, 'rearm');
    $config = $this->wp()->consumer();
    $scheduler = $processes->wakeups();

    $this->host->boundary()->run(static fn () => $scheduler->schedule($retry));
    self::assertNull($this->intentRow($retry->idempotencyKey)['step_index']);
    [$claimed] = $scheduler->claimDue($now, 10, 30);
    self::assertTrue($scheduler->complete($claimed));
    self::assertSame('done', $this->intentRow($retry->idempotencyKey)['status']);

    $this->host->boundary()->run(static fn () => $scheduler->schedule($retry));

    $row = $this->intentRow($retry->idempotencyKey);
    self::assertSame('pending', $row['status'], 're-armed');
    self::assertNull($row['step_index'], 'a ResumeRetry without a step index keeps none (its key reads "-")');
    self::assertSame('running', $row['expected_status']);
    $intent = array_values(array_filter($processes->pendingWakeups(), static fn (WakeupIntent $i) => $i->idempotencyKey === $retry->idempotencyKey));
    self::assertEquals([$retry], $intent, 'read back unchanged');
    self::assertSame($config->prefix(), $intent[0]->consumer);
  }

  // ── helpers ──────────────────────────────────────────────────────────────

  private function hook(string $name): string {
    return $this->wp()->consumer()->hook($name);
  }

  /** @return array<string, mixed> */
  private function intentRow(string $key): array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->wp()->consumer()->table('ddd_wakeups')}` WHERE idempotency_key = %s", $key), ARRAY_A);
    self::assertIsArray($row, "intent $key is stored");
    return $row;
  }

  /**
   * @param array<string, int|string> $args
   * @return list<int> pending Action Scheduler action ids on {prefix}_$hook with exactly $args
   */
  private function pendingActions(string $hook, array $args): array {
    $ids = as_get_scheduled_actions([
      'hook' => $this->hook($hook),
      'args' => $args,
      'status' => \ActionScheduler_Store::STATUS_PENDING,
      'per_page' => -1,
    ], 'ids');
    return array_values(array_map('intval', (array) $ids));
  }
}
