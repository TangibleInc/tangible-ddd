<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures;

use TangibleDDD\Application\Commands\ITransactionalCommand;

/** Transactional scenario command; its handler is supplied per scenario. */
final class CreateWidget implements ITransactionalCommand {

  public function __construct(
    public readonly string $widget_id,
    public readonly int $delay_seconds = 0,
  ) {}
}
