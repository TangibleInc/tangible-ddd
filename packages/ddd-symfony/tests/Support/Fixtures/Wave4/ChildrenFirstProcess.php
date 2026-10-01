<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** D3 AwaitAll over a dynamic key set computed at step time (children_first). */
final class ChildrenFirstProcess extends LongProcess {

  /** @param list<string> $children */
  public function __construct(public readonly array $children = ['c1', 'c2']) {
    parent::__construct(null);
  }

  protected function children_first(): Result {
    Trail::note('children_first:' . count($this->children));
    return new Result(await: AwaitAll::keyed(ChildGone::class, $this->children, timeout_seconds: 3600));
  }

  protected function purge_self(mixed $payload, AwaitAll $children): Result {
    $gathered = $children->gathered();
    sort($gathered);
    Trail::note('purge_self:' . implode(',', $gathered));
    return new Result();
  }
}
