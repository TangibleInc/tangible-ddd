<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\Billing\CommandHandlers;

use Doctrine\DBAL\Connection;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Symfony\Tests\Kernel\Billing\Commands\BillWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\Billing\Events\WidgetBilled;

/**
 * Consumer `bil`: writes a bill row and announces WidgetBilled (into bil's
 * outbox). It autowires the port interfaces; the bundle hands a `bil`-owned
 * service bil's ports, which the kernel test reads from $ports.
 */
final class BillWidgetHandler implements ICommandHandler {

  /** @var array{outbox: ?IOutboxStore, boundary: ?ITransactionBoundary} */
  public static array $ports = ['outbox' => null, 'boundary' => null];

  public function __construct(
    private readonly Connection $connection,
    private readonly EventsUnitOfWork $events,
    IOutboxStore $outbox,
    ITransactionBoundary $boundary,
  ) {
    self::$ports = ['outbox' => $outbox, 'boundary' => $boundary];
  }

  public function handle(ICommand $command): void {
    assert($command instanceof BillWidgetCommand);
    $this->connection->executeStatement('INSERT INTO billing.bills (widget_id) VALUES (?)', [$command->widget_id]);
    $this->events->record(new WidgetBilled($command->widget_id));
  }
}
