<?php

namespace TangibleDDD\Tests\Fakes\Intents;

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/** A command in a consumer-like namespace whose send() rides the given bus. */
class FakeSelfSendingCommand implements ICommand {
  use CommandBusAware;

  public static ?CommandBus $bus = null;

  protected static function container(): ContainerInterface {
    return new class(self::$bus) implements ContainerInterface {
      public function __construct(private ?CommandBus $bus) {}
      public function get(string $id): mixed { return $this->bus; }
      public function has(string $id): bool { return true; }
    };
  }
}
