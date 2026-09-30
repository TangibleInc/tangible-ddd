<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Reactions;

use TangibleDDD\Symfony\DependencyInjection\Attribute\AsDomainEventListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetFact;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;

/** An in-transaction domain-event reaction, by class and by marker. */
#[AsDomainEventListener(event: WidgetRegistered::class)]
#[AsDomainEventListener(event: WidgetFact::class, priority: 20, method: 'onAnyWidgetFact')]
final class CountRegistrations {

  /** @var list<string> */
  public static array $seen = [];

  public function __invoke(WidgetRegistered $event): void {
    self::$seen[] = 'class:' . $event->widget_id;
  }

  public function onAnyWidgetFact(WidgetFact $event): void {
    self::$seen[] = 'marker:' . $event->widget_id;
  }
}
