<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures;

use TangibleDDD\Application\Commands\ITransactionalCommand;

/** A command whose handler dispatches another command (the nesting guard). */
final class DispatchNested implements ITransactionalCommand {

  public function __construct(public readonly string $widget_id) {}
}
