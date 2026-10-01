<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Orm\CommandHandlers;

use Doctrine\ORM\EntityManagerInterface;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Orm\Commands\SaveOrmWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Orm\Entity\OrmWidget;

/** Never flushes itself: the transaction boundary flushes the EntityManager before COMMIT. */
final class SaveOrmWidgetHandler implements ICommandHandler {

  public function __construct(private readonly EntityManagerInterface $em) {}

  public function handle(ICommand $command): void {
    assert($command instanceof SaveOrmWidgetCommand);
    if ($command->rename) {
      $widget = $this->em->find(OrmWidget::class, $command->id) ?? throw new \RuntimeException("no widget {$command->id}");
      $widget->name = $command->name;
    } else {
      $this->em->persist(new OrmWidget($command->id, $command->name));
    }
    if ($command->failAfter) {
      throw new \DomainException('handler failed after scheduling an ORM change');
    }
  }
}
