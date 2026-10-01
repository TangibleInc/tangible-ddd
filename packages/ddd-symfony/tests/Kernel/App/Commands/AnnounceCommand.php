<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;
use TangibleDDD\Domain\Events\DomainEvent;

/** Announces one self-publishing fact through the outbox (a test producer for any fact). */
final class AnnounceCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(public readonly DomainEvent $fact) {}
}
