<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Listeners;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\SubscriberPriority;
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RecordListenerRunCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetFact;

/** D2: subscribes to a marker interface; runs after the plain listener. */
#[AsIntegrationListener(event: WidgetFact::class)]
#[SubscriberPriority(20)]
final class AnyWidgetFactListener {

  public function event_class(): string {
    return WidgetFact::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return new RecordListenerRunCommand('any-widget-fact', $event->widget_id);
  }
}
