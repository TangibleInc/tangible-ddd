<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers;

use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\OrderToyJobCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;

/**
 * Records the order (listener `toy-order`, the job id) with the act's cause
 * id, which inside a step is the deterministic step command id (D13).
 */
final class OrderToyJobHandler implements ICommandHandler {

  public function __construct(private readonly WidgetRepository $runs) {}

  public function handle(ICommand $command): void {
    assert($command instanceof OrderToyJobCommand);
    $this->runs->recordListenerRun('toy-order', $command->job_id, Correlation::peek()?->cause?->id);
  }
}
