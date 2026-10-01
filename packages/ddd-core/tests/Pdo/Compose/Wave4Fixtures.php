<?php

declare(strict_types=1);

/**
 * Wave-4 fixtures of the DurableRuntime cases (consumer `pdocompose`, see
 * Fixtures.php): a D1 external effect and its repair, a D7 long alarm, a D3
 * keyed await, and a process that strands mid-step for the operator repairs.
 */

namespace TangibleDDD\Core\Tests\Pdo\Compose;

require_once __DIR__ . '/Fixtures.php';

use TangibleDDD\Application\Commands\Command;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Process\AwaitAlarm;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;

// ── D1: an external effect and its repair ──────────────────────────────────

/** Creates a customer at a fake payment provider (D1). */
final class CreateCustomer extends Command implements IExternalEffectCommand {

  /** @var int perform() calls, i.e. how often the provider was hit */
  public static int $performed = 0;
  /** @var list<string> external refs record() saw */
  public static array $recorded = [];
  public static int $failRecord = 0;

  public function __construct(public readonly int $account_id) {}

  public static function reset(): void {
    self::$performed = 0;
    self::$recorded = [];
    self::$failRecord = 0;
  }

  public function idempotency_key(): string {
    return "provider:customer:{$this->account_id}";
  }

  public function perform(): EffectResult {
    self::$performed++;
    return new EffectResult(['customer' => 'cus_' . self::$performed], 'cus_' . self::$performed);
  }

  public function record(EffectResult $r): void {
    if (self::$failRecord > 0) {
      self::$failRecord--;
      throw new \RuntimeException('record failed on purpose');
    }
    self::$recorded[] = (string) $r->external_ref;
  }

  public function failure_command(\Throwable $last): ?ICommand {
    return null;
  }
}

/** The operator repair: invalidate the journal entry in the command's own transaction. */
final class RepairCustomer extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $account_id, public readonly bool $fail = false) {}

  protected function handle(IEffectJournal $journal): void {
    $journal->invalidate("provider:customer:{$this->account_id}", 'operator: customer deleted at the provider');
    if ($this->fail) {
      throw new \RuntimeException('repair failed after invalidating');
    }
  }
}

// ── D7: a long absolute alarm ──────────────────────────────────────────────

final class StartAlarm extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $seconds) {}

  protected function handle(ProcessRunner $runner): void {
    $runner->start(new AlarmSaga($this->seconds));
  }
}

final class AlarmSaga extends LongProcess {
  public function __construct(public readonly int $seconds = 90000) {
    parent::__construct(null);
  }

  protected function wait(): Result {
    Trace::note('alarm-set');
    return new Result(await: AwaitAlarm::after($this->seconds));
  }

  protected function fire(mixed $payload, mixed $arrival): Result {
    Trace::note('alarm-fired');
    return new Result();
  }
}

// ── D3: a keyed await on a minted ref ──────────────────────────────────────

final class JobDone extends IntegrationEvent implements IAwaitKeyed {
  public function __construct(public readonly string $job_id = '') {}

  public function await_key(): ?string {
    return $this->job_id === '' ? null : $this->job_id;
  }
}

final class JobWasDone extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly string $job_id) {}
  public function payload(): array { return ['job_id' => $this->job_id]; }
  public function to_integration(): JobDone { return new JobDone($this->job_id); }
}

final class ReportJob extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly string $job_id) {}

  protected function handle(): void {
    $this->event(new JobWasDone($this->job_id));
  }
}

final class StartJob extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly string $name) {}

  protected function handle(ProcessRunner $runner): void {
    $runner->start(new KeyedJobSaga($this->name));
  }
}

#[Awaits(JobDone::class)]
final class KeyedJobSaga extends LongProcess {
  /** @var array<string, string> saga name => the job ref it minted */
  public static array $refs = [];

  public function __construct(public readonly string $name = '') {
    parent::__construct(null);
  }

  protected function order(): Result {
    $ref = $this->step_ref('job');
    self::$refs[$this->name] = $ref;
    Trace::note("ordered:{$this->name}");
    return new Result(await: AwaitEvent::keyed(JobDone::class, $ref));
  }

  protected function done(mixed $payload, JobDone $done): Result {
    Trace::note("done:{$this->name}");
    return new Result();
  }
}

// ── operator repairs: a step whose worker dies ─────────────────────────────

final class StartFragile extends SelfHandlingCommand implements ITransactionalCommand {
  protected function handle(ProcessRunner $runner): void {
    $runner->start(new FragileSaga());
  }
}

final class FragileSaga extends LongProcess {
  /** Set by a test: the step "dies" (the closure throws an \Error the runner does not catch as a step failure). */
  public static ?\Closure $onWork = null;

  public function __construct() {
    parent::__construct(null);
  }

  protected function work(): Result {
    Trace::note('fragile-work');
    if (self::$onWork !== null) {
      (self::$onWork)();
    }
    return new Result();
  }
}
