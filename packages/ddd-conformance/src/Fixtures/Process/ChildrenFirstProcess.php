<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * D3 AwaitAll over a checkpointed dynamic key set (process.await-all-dynamic;
 * TXP's children_first).
 *
 * `children_first` (step 0) computes the keys at step time
 * (`{widget}:{child}` for each child), checkpoints them and awaits a
 * ChildPurged for each, with a 1 h alarm. An empty set does not suspend.
 * `purge_self` (step 1) journals `purge_self:{widget}:{gathered keys in
 * arrival order}` and sends StepCommand('purge_self', widget).
 */
final class ChildrenFirstProcess extends LongProcess {

  public const TIMEOUT_SECONDS = 3600;

  /** @param list<string> $children */
  public function __construct(
    public readonly string $widget_id = 'w-1',
    public readonly array $children = ['c1', 'c2'],
  ) {
    parent::__construct(null);
  }

  protected function children_first(): Result {
    $keys = array_map(fn (string $child) => "{$this->widget_id}:$child", $this->children);
    ProcessJournal::step("children_first:{$this->widget_id}:" . count($keys));
    return new Result(
      await: AwaitAll::keyed(ChildPurged::class, $keys, self::TIMEOUT_SECONDS),
      checkpoint: new StepNote('children', $keys),
    );
  }

  protected function purge_self(mixed $payload, AwaitAll $children): Result {
    ProcessJournal::step("purge_self:{$this->widget_id}:" . implode(',', array_map('strval', $children->gathered())));
    return new Result(commands: [new StepCommand('purge_self', $this->widget_id)]);
  }
}
