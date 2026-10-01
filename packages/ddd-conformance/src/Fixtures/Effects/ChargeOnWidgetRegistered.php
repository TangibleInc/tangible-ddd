<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Effects;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * The fact-triggered effect (IntegrationTranslator shape, registered through
 * the core SubscriptionRegistrar): WidgetRegistered → ChargeWidget. The
 * registrar sends the command under uuid5(event_id, subscriber id) and, on
 * budget exhaustion, sends its failureCommand() under
 * uuid5(event_id, "{subscriber id}#failure").
 */
final class ChargeOnWidgetRegistered {

  public const SUBSCRIBER_ID = 'listener:' . self::class;

  public function event_class(): string {
    return WidgetRegistered::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return $event instanceof WidgetRegistered ? new ChargeWidget($event->widget_id) : null;
  }
}
