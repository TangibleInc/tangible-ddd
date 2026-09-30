<?php

namespace TangibleDDD\Domain\Events;

/**
 * Shared root of the event partition: name/prefix machinery only.
 * DomainEvent (raisable) and IntegrationEvent (derived-only record)
 * both extend this and NOTHING else is shared between them.
 */
abstract class Event {

  /**
   * The consumer prefix scoping this event's hook names.
   *
   * Default (0.2.5c): resolved from the registry — the concrete leaf's
   * namespace IS the consumer identity, so events extend the framework
   * bases directly with no stamped middle class. A consumer whose plugin
   * never boot()ed fails loudly (NoConsumerOwnsClass) instead of silently
   * publishing under a wrong name. Stamped bases that still override this
   * keep winning — the default is additive.
   *
   * (Unmemoized on purpose: owner_of() is a short loop over few consumers,
   * and a static memo would go stale across test registry resets.)
   */
  protected static function prefix(): string {
    return \TangibleDDD\Infra\Consumers\ConsumerRegistry::owner_of(static::class)->prefix();
  }

  /**
   * Short event name. Default: derive from class name (UserEarned -> user_earned).
   */
  public static function name(): string {
    $class = (new \ReflectionClass(static::class))->getShortName();
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
  }
}
