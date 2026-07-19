<?php

namespace TangibleDDD\Application\CQRS;

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;

/**
 * Trait for commands that can dispatch themselves via the command bus.
 *
 * container() defaults to registry resolution (0.2.5c): the concrete
 * command's namespace names its consumer, owner_of() supplies the
 * container, ->send() rides that consumer's bus — no stamped base needed:
 *
 *   class IssueEarning implements ICommand { use CommandBusAware; ... }
 *
 * A base class overriding container() keeps winning (the framework's own
 * self-consumer Command base does — its commands must dispatch before and
 * regardless of registry state). An unowned command fails loudly
 * (NoConsumerOwnsClass) instead of riding a wrong bus.
 *
 * No bus cache (0.2.5c): the old per-using-class static saved one array
 * lookup on a compiled container and cost a hidden static that went stale
 * across registry changes. Resolution is per send, always current.
 */
trait CommandBusAware {

  /** The owning consumer's DI container. Override to pin one explicitly. */
  protected static function container(): ContainerInterface {
    return ConsumerRegistry::owner_of(static::class)->container();
  }

  public function send(): mixed {
    return static::container()->get(CommandBus::class)->handle($this);
  }
}
