<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Domain\Events\IntegrationEvent;

/** One child of a fan-in (D3 dynamic AwaitAll), keyed on the child id. */
final class ChildPurged extends IntegrationEvent implements IAwaitKeyed {

  public function __construct(public readonly string $child_id = '') {}

  public function await_key(): ?string {
    return $this->child_id;
  }

  protected static function prefix(): string {
    return 'acme';
  }
}
