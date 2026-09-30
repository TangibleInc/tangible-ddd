<?php

namespace TangibleDDD\Application\CQRS;

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;

/**
 * Trait for queries that can dispatch themselves via the query bus.
 *
 * container() defaults to registry resolution (0.2.5c) — same contract as
 * CommandBusAware: the concrete query's namespace names its consumer, no
 * stamped base needed, overrides keep winning, unowned classes fail loudly.
 * No bus cache: resolution is per send, always current.
 */
trait QueryBusAware {

  /** The owning consumer's DI container. Override to pin one explicitly. */
  protected static function container(): ContainerInterface {
    return ConsumerRegistry::owner_of(static::class)->container();
  }

  public function send(): mixed {
    /** @var CommandBus $bus */
    $bus = static::container()->get('tactician.query_bus');

    return $bus->handle($this);
  }
}
