<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * Prepares (step 0), then gathers a PartArrived of its widget for every
 * part with a 60 s alarm (step 1), then assembles (step 2). On a FAIL
 * timeout the completed preparation is compensated (`undo_prepare`).
 *
 * Keys are `{widget_id}:{part}`, so processes of different widgets never
 * accept each other's parts. `assemble` journals the gathered keys:
 * `assemble:w-1:a,w-1:b`.
 */
final class GatherPartsProcess extends LongProcess {

  public const TIMEOUT_SECONDS = 60;

  /** @param list<string> $parts */
  public function __construct(
    public readonly string $widget_id = 'w-1',
    public readonly array $parts = ['a', 'b'],
    public readonly string $policy = AwaitAll::TIMEOUT_FAIL,
  ) {
    parent::__construct(null);
  }

  protected function prepare(): Result {
    ProcessJournal::step('prepare');
    return new Result(commands: [new StepCommand('prepare', $this->widget_id)]);
  }

  protected function gather(): Result {
    ProcessJournal::step('gather');
    return new Result(await: new AwaitAll(
      event_class: PartArrived::class,
      expected: array_map(fn (string $part) => "{$this->widget_id}:$part", $this->parts),
      key_by: [self::class, 'key'],
      timeout_seconds: self::TIMEOUT_SECONDS,
      on_timeout: $this->policy,
    ));
  }

  protected function assemble(mixed $payload, AwaitAll $gathered): Result {
    $keys = array_map('strval', $gathered->gathered());
    sort($keys);
    ProcessJournal::step('assemble:' . implode(',', $keys));
    return new Result(commands: [new StepCommand('assemble', $this->widget_id)]);
  }

  #[Compensates('prepare')]
  protected function undo_prepare(\Throwable $cause, mixed $checkpoint): Result {
    ProcessJournal::step('undo_prepare');
    return new Result(commands: [new StepCommand('undo_prepare', $this->widget_id)]);
  }

  public static function key(PartArrived $e): string {
    return "{$e->widget_id}:{$e->part}";
  }
}
