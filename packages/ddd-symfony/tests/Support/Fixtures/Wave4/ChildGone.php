<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Domain\Events\IntegrationEvent;

/** D3: one child of a dynamic AwaitAll key set reports itself gone. */
final class ChildGone extends IntegrationEvent implements IAwaitKeyed {

  public function __construct(public readonly string $child = '') {}

  public function await_key(): ?string {
    return $this->child === '' ? null : $this->child;
  }

  protected static function prefix(): string {
    return 'sft';
  }
}
