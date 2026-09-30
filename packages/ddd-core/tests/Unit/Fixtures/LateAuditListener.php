<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\SubscriberPriority;

/** Declares a priority after resume (100), as today's integration_action() callers may. */
#[SubscriberPriority(100)]
final class LateAuditListener {

  public function event_class(): string {
    return BillingFact::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return new RecordingCommand('late-audit');
  }
}
