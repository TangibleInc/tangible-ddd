<?php

namespace TangibleDDD\Application\CQRS;

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;

/**
 * Trait for commands that can dispatch themselves via the command bus.
 *
 * Consumer must provide a base Command class that implements the container() method:
 *
 * abstract class Command {
 *   use CommandBusAware;
 *
 *   protected static function container(): ContainerInterface {
 *     return \MyPlugin\Plugin\di();
 *   }
 * }
 */
trait CommandBusAware {
  private static CommandBus $command_bus;

  /**
   * Return the DI container.
   * Consumer's base class must implement this.
   */
  abstract protected static function container(): ContainerInterface;

  private static function init_bus(): void {
    static::$command_bus = static::container()->get(CommandBus::class);
  }

  public function send(): mixed {
    if (!isset(static::$command_bus)) {
      static::init_bus();
    }

    return static::$command_bus->handle($this);
  }
}
