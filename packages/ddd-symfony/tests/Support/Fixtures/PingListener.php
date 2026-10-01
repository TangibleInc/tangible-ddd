<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/** A listener in the IntegrationTranslator shape (event_class() + translate()). */
final class PingListener {

  public static int $constructed = 0;

  /** @var list<string> */
  public static array $log = [];

  public function __construct() {
    self::$constructed++;
  }

  public function event_class(): string {
    return PingFact::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    return new RecordingCommand('ping:' . $event->n);
  }
}
