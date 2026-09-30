<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Listeners;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\SubscriberPriority;
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;

/** Throws for widgets named boom-*; otherwise does nothing. Runs last. */
#[AsIntegrationListener(event: WidgetRegistered::class)]
#[SubscriberPriority(30)]
final class FlakyListener {

  public function event_class(): string {
    return WidgetRegistered::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    if (str_starts_with($event->widget_id, 'boom')) {
      throw new \RuntimeException("flaky listener refuses {$event->widget_id}");
    }
    return null;
  }
}
