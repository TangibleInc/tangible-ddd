<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;

/** Self-handling, method-injecting a PRIVATE service, returning a value (E S2, D11). */
final class RenameWidgetCommand extends SelfHandlingCommand implements ITransactionalCommand {

  public function __construct(public readonly string $widget_id, public readonly string $name) {}

  protected function handle(WidgetRepository $widgets): array {
    return ['renamed' => $widgets->rename($this->widget_id, $this->name)];
  }
}
