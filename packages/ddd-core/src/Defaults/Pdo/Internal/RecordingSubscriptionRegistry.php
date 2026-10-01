<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;

/**
 * ISubscriptionRegistry decorator that remembers the subscribed fact
 * classes, so DurableRuntime can map an event type (Event::name()) back to
 * its class for a deliver job whose outbox row recorded none (a row written
 * before FactClassRecordingEventBus, or by another writer).
 *
 * @internal
 */
final class RecordingSubscriptionRegistry implements ISubscriptionRegistry {

  /** @var array<string, true> */
  private array $classes = [];

  public function __construct(private readonly ISubscriptionRegistry $inner) {}

  public function add(Subscriber $s): void {
    $this->inner->add($s);
    $this->classes[$s->eventClassOrMarker] = true;
  }

  public function for(string $eventClass): array {
    return $this->inner->for($eventClass);
  }

  /** @return list<string> concrete classes and marker interfaces, in first-subscription order */
  public function subscribedClasses(): array {
    return array_keys($this->classes);
  }
}
