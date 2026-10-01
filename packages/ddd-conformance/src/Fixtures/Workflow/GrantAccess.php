<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Workflow;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;

/**
 * The command a GrantWorkflow work item dispatches, through the host bus
 * (GrantWorkflow::$bus). The scenario's handler commits `grant:{command id}`
 * once per command id.
 */
final class GrantAccess implements ICommand, ITransactionalCommand {

  public function __construct(public readonly string $item_key) {}

  public static function row(string $commandId): string {
    return "grant:$commandId";
  }

  public function send(): mixed {
    return GrantWorkflow::bus()->handle($this);
  }
}
