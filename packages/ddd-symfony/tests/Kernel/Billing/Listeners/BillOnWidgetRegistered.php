<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\Billing\Listeners;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;
use TangibleDDD\Symfony\Tests\Kernel\Billing\Commands\BillWidgetCommand;

/** Consumer `bil` hears the app consumer's fact (cross-consumer delivery). */
#[AsIntegrationListener]
final class BillOnWidgetRegistered {

  public function event_class(): string {
    return WidgetRegistered::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return new BillWidgetCommand($event->widget_id);
  }
}
