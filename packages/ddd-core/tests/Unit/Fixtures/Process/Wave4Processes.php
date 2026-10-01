<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitAlarm;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitAny;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Application\Process\IPrecheckAwait;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\PrecheckSatisfied;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\RetryStep;
use TangibleDDD\Core\Tests\Unit\Fixtures\AppDestroyScheduled;
use TangibleDDD\Core\Tests\Unit\Fixtures\ChildPurged;
use TangibleDDD\Core\Tests\Unit\Fixtures\JobFinished;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Testing\InMemoryEffectJournal;

/** A checkpoint/payload value for the wave-4 fixtures. */
final class Note extends DirectJsonLifecycleValue {

  public function __construct(public string $text = '', public array $items = []) {
    parent::__construct();
  }

  protected static function from_json_instance(\stdClass|array $data, ...$params): static {
    $data = (array) $data;
    return new static((string) ($data['text'] ?? ''), (array) ($data['items'] ?? []));
  }
}

/**
 * D3 keyed await: orders a job whose id the process mints, awaits the
 * JobFinished keyed on that id, then records the outcome. The order step
 * checkpoints the job id; its compensation sees the checkpoint.
 */
final class KeyedJobProcess extends LongProcess {

  /** @var list<?string> checkpoints seen by the compensation */
  public static array $undone = [];

  public function __construct(public readonly int $app_id = 7) {
    parent::__construct(null);
  }

  protected function order(): Result {
    $job = $this->step_ref('job');
    Journal::note('order:' . $job);
    return new Result(
      commands: [new RecordingCommand('order-job', $job)],
      await: AwaitEvent::keyed(JobFinished::class, $job),
      checkpoint: new Note($job),
    );
  }

  protected function record(mixed $payload, JobFinished $done): Result {
    Journal::note('record:' . ($done->ok ? 'ok' : 'failed'));
    if (!$done->ok) {
      throw new \RuntimeException('job failed');
    }
    return new Result();
  }

  protected function finish(): Result {
    Journal::note('finish');
    return new Result();
  }

  #[Compensates('order')]
  protected function unorder(\Throwable $cause, ?Note $checkpoint): Result {
    self::$undone[] = $checkpoint?->text;
    Journal::note('unorder');
    return new Result();
  }
}

/**
 * D3 register-then-check: awaits JobFinished keyed on a minted ref and
 * prechecks a readiness flag (the owner's published state).
 */
final class ReadinessProcess extends LongProcess implements IPrecheckAwait {

  public static bool $ready = false;
  /** @var list<?string> the process status the precheck saw in the store */
  public static array $seenStatus = [];
  /** @var null|\Closure(): ?string reads the stored status (set by the test) */
  public static ?\Closure $statusProbe = null;

  public function __construct(public readonly int $app_id = 3) {
    parent::__construct(null);
  }

  protected function await_ready(): Result {
    Journal::note('await_ready');
    return new Result(
      commands: [new RecordingCommand('ask-ready')],
      await: AwaitEvent::keyed(JobFinished::class, $this->step_ref('ready'), timeout_seconds: 600),
    );
  }

  protected function provision(mixed $payload, mixed $arrival): Result {
    Journal::note('provision:' . get_debug_type($arrival));
    return new Result();
  }

  public function already_satisfied(IAwaitMechanism $await): ?PrecheckSatisfied {
    self::$seenStatus[] = self::$statusProbe !== null ? (self::$statusProbe)() : null;
    return self::$ready ? PrecheckSatisfied::with('precheck') : null;
  }
}

/**
 * D3 any-of with cancellation: waits for its job, or is cancelled by the
 * destruction of its app (the earlier step is compensated).
 */
final class CancellableSyncProcess extends LongProcess {

  public function __construct(public readonly int $app_id = 9) {
    parent::__construct(null);
  }

  protected function prepare(): Result {
    Journal::note('prepare:' . $this->app_id);
    return new Result();
  }

  protected function sync(): Result {
    Journal::note('sync:' . $this->app_id);
    $job = $this->step_ref('sync');
    return new Result(
      commands: [new RecordingCommand('sync-job', $job)],
      await: AwaitAny::of(AwaitEvent::keyed(JobFinished::class, $job))
        ->cancelledBy(new AwaitEvent(AppDestroyScheduled::class, ['app_id' => $this->app_id])),
    );
  }

  protected function complete_sync(mixed $payload, JobFinished $done): Result {
    Journal::note('complete_sync:' . $this->app_id);
    return new Result();
  }

  #[Compensates('prepare')]
  protected function unprepare(\Throwable $cause, mixed $checkpoint): Result {
    Journal::note('unprepare:' . $this->app_id . ':' . $cause->getMessage());
    return new Result();
  }
}

/**
 * D3 AwaitAll over a checkpointed dynamic key set (children_first): the
 * child ids are computed at step time and checkpointed with the await.
 */
final class ChildrenFirstProcess extends LongProcess {

  public function __construct(public readonly array $children = ['c1', 'c2']) {
    parent::__construct(null);
  }

  protected function children_first(): Result {
    Journal::note('children_first:' . count($this->children));
    return new Result(
      await: AwaitAll::keyed(ChildPurged::class, $this->children, timeout_seconds: 3600),
      checkpoint: new Note('children', $this->children),
    );
  }

  protected function purge_self(mixed $payload, AwaitAll $children): Result {
    Journal::note('purge_self:' . implode(',', $children->gathered()));
    return new Result();
  }
}

/** D7: a long absolute-UTC alarm, then a step. */
final class AlarmProcess extends LongProcess {

  public function __construct(public readonly ?string $at = null, public readonly int $after = 90000) {
    parent::__construct(null);
  }

  protected function wait(): Result {
    Journal::note('wait');
    return new Result(await: $this->at !== null
      ? AwaitAlarm::at(new \DateTimeImmutable($this->at))
      : AwaitAlarm::after($this->after));
  }

  protected function fire(mixed $payload, mixed $arrival): Result {
    Journal::note('fire');
    return new Result(commands: [new RecordingCommand('fired')]);
  }
}

/** D7 + D3: a fact or a deadline, whichever comes first (proceed on timeout). */
final class FactOrDeadlineProcess extends LongProcess {

  public function __construct(public readonly string $until = '2026-10-04T12:00:00+00:00') {
    parent::__construct(null);
  }

  protected function wait(): Result {
    Journal::note('wait');
    return new Result(await: AwaitAny::of(new AwaitEvent(AppDestroyScheduled::class, ['app_id' => 1]))
      ->until(new \DateTimeImmutable($this->until), AwaitAll::TIMEOUT_PROCEED));
  }

  protected function after(mixed $payload, ?AppDestroyScheduled $fact): Result {
    Journal::note('after:' . ($fact === null ? 'deadline' : 'fact'));
    return new Result();
  }
}

/**
 * D1 inside a step: the step performs an effect (journaled by its key) and
 * then a flaky record fails; the step's retry policy re-runs it, and the
 * journal makes the re-run reuse the performed result.
 */
final class EffectStepProcess extends LongProcess {

  public static ?InMemoryEffectJournal $journal = null;
  public static int $performed = 0;
  public static int $recordFailures = 0;
  /** @var list<string> */
  public static array $keys = [];

  public function __construct(public readonly int $customer = 4) {
    parent::__construct(null);
  }

  public static function reset(int $recordFailures = 0): void {
    self::$journal = new InMemoryEffectJournal();
    self::$performed = 0;
    self::$recordFailures = $recordFailures;
    self::$keys = [];
  }

  #[RetryStep(attempts: 2, backoff_seconds: 30)]
  protected function charge(): Result {
    $key = $this->step_ref('charge');
    self::$keys[] = $key;
    Journal::note('charge');
    if (self::$journal->find($key) === null) {
      self::$performed++;
      self::$journal->store($key, new EffectResult(['charge_id' => 'ch_1']));
    }
    if (self::$recordFailures > 0) {
      self::$recordFailures--;
      throw new \RuntimeException('record failed');
    }
    return new Result(commands: [new RecordingCommand('charged', $key)]);
  }

  protected function done(): Result {
    Journal::note('done');
    return new Result();
  }
}

/** A step with the default policy (0 retries): a failure compensates at once. */
final class NoRetryProcess extends LongProcess {

  public function __construct() {
    parent::__construct(null);
  }

  protected function first(): Result {
    Journal::note('first');
    return new Result();
  }

  protected function second(): Result {
    Journal::note('second');
    throw new \RuntimeException('second failed');
  }

  #[Compensates('first')]
  protected function undo_first(\Throwable $cause, mixed $checkpoint): Result {
    Journal::note('undo_first');
    return new Result();
  }
}

/** A suspending step whose dispatch fails once; retried, its await is withdrawn first. */
final class RetriedAwaitProcess extends LongProcess {

  public static int $failures = 1;

  public function __construct() {
    parent::__construct(null);
  }

  #[RetryStep(attempts: 1)]
  protected function ask(): Result {
    Journal::note('ask');
    return new Result(
      commands: [new RecordingCommand('ask')],
      await: AwaitEvent::keyed(JobFinished::class, $this->step_ref('ask')),
    );
  }

  protected function answer(mixed $payload, JobFinished $done): Result {
    Journal::note('answer');
    return new Result();
  }
}

/**
 * D1 in reaction to an awaited answer: the post-await step (non-nullable
 * typed fact) fails once and is retried by its policy; the retry must see
 * the same fact.
 */
final class RetriedAnswerProcess extends LongProcess {

  public static int $failures = 1;

  public function __construct() {
    parent::__construct(null);
  }

  protected function ask(): Result {
    Journal::note('ask');
    return new Result(
      commands: [new RecordingCommand('ask')],
      await: AwaitEvent::keyed(JobFinished::class, $this->step_ref('ask')),
    );
  }

  #[RetryStep(attempts: 2, backoff_seconds: 10)]
  protected function answer(mixed $payload, JobFinished $done): Result {
    Journal::note('answer:' . $done->job_id);
    if (self::$failures > 0) {
      self::$failures--;
      throw new \RuntimeException('effect transport down');
    }
    return new Result();
  }
}

/** A retried post-gather step: the retry must see the same AwaitAll tally. */
final class RetriedGatherProcess extends LongProcess {

  public static int $failures = 1;

  public function __construct() {
    parent::__construct(null);
  }

  protected function gather(): Result {
    return new Result(await: AwaitAll::keyed(ChildPurged::class, ['c1', 'c2'], timeout_seconds: 3600));
  }

  #[RetryStep(attempts: 1)]
  protected function judge(mixed $payload, AwaitAll $children): Result {
    Journal::note('judge:' . implode(',', $children->gathered()));
    if (self::$failures > 0) {
      self::$failures--;
      throw new \RuntimeException('judge failed');
    }
    return new Result();
  }
}

/** An #[Async] post-await step: its continuation must still see the fact. */
final class AsyncAnswerProcess extends LongProcess {

  public function __construct() {
    parent::__construct(null);
  }

  protected function ask(): Result {
    return new Result(await: AwaitEvent::keyed(JobFinished::class, $this->step_ref('ask')));
  }

  #[\TangibleDDD\Application\Process\Async]
  protected function answer(mixed $payload, JobFinished $done): Result {
    Journal::note('async-answer:' . $done->job_id);
    return new Result();
  }
}

/** A 0.6-shaped unkeyed await (no key, criteria only): first-wins on one fact. */
final class UnkeyedWaitProcess extends LongProcess {

  public function __construct(public readonly string $name = 'a') {
    parent::__construct(null);
  }

  protected function wait(): Result {
    return new Result(await: new AwaitEvent(\TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined::class));
  }

  protected function joined(mixed $payload, \TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined $fact): Result {
    Journal::note('joined:' . $this->name . ':' . $fact->user_id);
    return new Result();
  }
}
