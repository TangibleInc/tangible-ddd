<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Application\Commands\ICommand;

/** The compensation of V8Charge: records each send. */
final class V8ChargeFailed implements ICommand {

  /** @var list<array{n: int, error: string}> */
  public static array $sent = [];

  public function __construct(public readonly int $n, public readonly string $error) {}

  public function send(): mixed {
    self::$sent[] = ['n' => $this->n, 'error' => $this->error];
    return null;
  }
}
