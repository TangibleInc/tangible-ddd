<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Orm\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/**
 * Persists a new OrmWidget, or renames an existing one, through the ORM. The
 * bundle flushes the EntityManager before COMMIT; $failAfter throws from the
 * handler after the change is scheduled.
 */
final class SaveOrmWidgetCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(
    public readonly string $id,
    public readonly string $name,
    public readonly bool $failAfter = false,
    public readonly bool $rename = false,
  ) {}
}
