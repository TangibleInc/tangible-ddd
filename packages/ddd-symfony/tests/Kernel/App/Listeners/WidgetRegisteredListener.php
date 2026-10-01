<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Listeners;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RecordListenerRunCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;

/** Discovered at compile time; must not be constructed at boot (E S4). */
#[AsIntegrationListener]
final class WidgetRegisteredListener {

  public static int $constructed = 0;

  public function __construct() {
    self::$constructed++;
  }

  public function event_class(): string {
    return WidgetRegistered::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return new RecordListenerRunCommand('registered', $event->widget_id);
  }
}
