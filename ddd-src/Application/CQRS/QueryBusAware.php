<?php

namespace TangibleDDD\Application\CQRS;

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;

/**
 * Trait for queries that can dispatch themselves via the query bus.
 *
 * Consumer must provide a base Query class that implements the container() method:
 *
 * abstract class Query {
 *   use QueryBusAware;
 *
 *   protected static function container(): ContainerInterface {
 *     return \MyPlugin\Plugin\di();
 *   }
 * }
 */
trait QueryBusAware {
  private static CommandBus $query_bus;

  /**
   * Return the DI container.
   * Consumer's base class must implement this.
   */
  abstract protected static function container(): ContainerInterface;

  private static function init_query_bus(): void {
    /** @var CommandBus $bus */
    $bus = static::container()->get('tactician.query_bus');
    static::$query_bus = $bus;
  }

  public function send(): mixed {
    if (!isset(static::$query_bus)) {
      static::init_query_bus();
    }

    return static::$query_bus->handle($this);
  }
}
