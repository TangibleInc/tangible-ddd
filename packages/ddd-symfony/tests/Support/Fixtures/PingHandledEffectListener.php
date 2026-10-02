<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/** Translates PingFact into the always-failing E1 PingHandledEffect (E3). */
final class PingHandledEffectListener {

  public function event_class(): string {
    return PingFact::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return new PingHandledEffect($event->n);
  }
}
