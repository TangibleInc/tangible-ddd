<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;

/**
 * The fresh process's "one committed command" (FreshProcesses::publish_fresh):
 * an application command, as a raw-PHP host declares one, whose handler
 * records the fact. It is an ICommand so DurableRuntime::compose()'s
 * handler array maps it (the scenario fixture CreateWidget is only an
 * ITransactionalCommand, which compose() files as a service; see
 * wave3-pdo-conformance-change-requests.md, CR-PCF-2).
 */
final class PublishFact implements ICommand, ITransactionalCommand {

  public function __construct(public readonly string $label = 'fresh-publish') {}

  public function send(): mixed {
    throw new \LogicException('PublishFact is dispatched through DurableRuntime::bus() in bin/fresh.php');
  }
}
