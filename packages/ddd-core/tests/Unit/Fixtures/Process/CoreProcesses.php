<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Process;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;

/**
 * Process fixtures for the core ProcessRunner suite (mem doubles, no
 * WordPress). Every step records what ran in a static journal, because the
 * mem store hands back copies (as a database would), not the instance.
 */
final class Journal {
  /** @var list<string> */
  public static array $steps = [];
  /** @var list<?string> ambient cause kind seen by each step */
  public static array $causes = [];

  public static function reset(): void {
    self::$steps = [];
    self::$causes = [];
  }

  public static function note(string $step): void {
    self::$steps[] = $step;
    self::$causes[] = Correlation::peek()?->cause?->kind->name;
  }
}

/** Two plain steps, each sending a command. */
final class TwoStepProcess extends LongProcess {

  public function __construct(public readonly int $order_id = 1) {
    parent::__construct(null);
  }

  protected function reserve(): Result {
    Journal::note('reserve');
    return new Result(commands: [new RecordingCommand('reserve', $this->order_id)]);
  }

  protected function ship(): Result {
    Journal::note('ship');
    return new Result(commands: [new RecordingCommand('ship', $this->order_id)]);
  }
}

/** Waits for the matching UserJoined, then greets that user. */
final class AwaitingProcess extends LongProcess {

  public function __construct(public readonly int $user_id = 1) {
    parent::__construct(null);
  }

  protected function invite(): Result {
    Journal::note('invite');
    return new Result(
      commands: [new RecordingCommand('invite', $this->user_id)],
      await: new AwaitEvent(UserJoined::class, ['user_id' => $this->user_id]),
    );
  }

  protected function greet(mixed $payload, UserJoined $joined): Result {
    Journal::note('greet');
    return new Result(commands: [new RecordingCommand('greet', $joined->user_id)]);
  }
}

/**
 * Prepares (step 0), then gathers two users with a 60 s alarm (step 1); on
 * a FAIL timeout the completed preparation is compensated.
 */
final class TimedGatherProcess extends LongProcess {

  public function __construct(public readonly string $policy = AwaitAll::TIMEOUT_FAIL) {
    parent::__construct(null);
  }

  protected function prepare(): Result {
    Journal::note('prepare');
    return new Result();
  }

  protected function gather(): Result {
    Journal::note('gather');
    return new Result(await: new AwaitAll(
      event_class: UserJoined::class,
      expected: [1, 2],
      key_by: [self::class, 'key'],
      timeout_seconds: 60,
      on_timeout: $this->policy,
    ));
  }

  protected function report(mixed $payload, AwaitAll $gather): Result {
    Journal::note('report');
    return new Result();
  }

  #[Compensates('prepare')]
  protected function undo_prepare(\Throwable $cause, mixed $checkpoint): Result {
    Journal::note('undo_prepare');
    return new Result(commands: [new RecordingCommand('undo')]);
  }

  public static function key(UserJoined $e): int {
    return $e->user_id;
  }
}

/** Charges, then fails; the charge is refunded. */
final class RefundingProcess extends LongProcess {

  public function __construct() {
    parent::__construct(null);
  }

  protected function charge(): Result {
    Journal::note('charge');
    return new Result(commands: [new RecordingCommand('charge')]);
  }

  protected function deliver(): Result {
    Journal::note('deliver');
    throw new \RuntimeException('courier lost the parcel');
  }

  #[Compensates('charge')]
  protected function refund(\Throwable $cause, mixed $checkpoint): Result {
    Journal::note('refund');
    return new Result(commands: [new RecordingCommand('refund')]);
  }
}

/** Its second step throws and its compensation throws too. */
final class BrokenCompensationProcess extends LongProcess {

  public function __construct() {
    parent::__construct(null);
  }

  protected function first(): Result {
    Journal::note('first');
    return new Result();
  }

  protected function second(): Result {
    throw new \RuntimeException('second failed');
  }

  #[Compensates('first')]
  protected function undo_first(\Throwable $cause, mixed $checkpoint): Result {
    throw new \LogicException('compensation broke');
  }
}

/** Ignites on OrderPlaced (declines order 0) and runs one step. */
#[StartsOn(OrderPlaced::class)]
final class IgnitedProcess extends LongProcess {

  public function __construct(public readonly int $order_id = 0) {
    parent::__construct(null);
  }

  public static function from_event(OrderPlaced $event): ?static {
    return $event->order_id === 0 ? null : new static($event->order_id);
  }

  protected function open(): Result {
    Journal::note('open:' . $this->order_id);
    return new Result();
  }
}
