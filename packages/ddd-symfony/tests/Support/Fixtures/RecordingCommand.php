<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Commands\ICommand;

/** A command whose send() only records itself (unit tests without a bus). */
final class RecordingCommand implements ICommand {

  /** @var list<string> */
  public static array $sent = [];

  public function __construct(public readonly string $label) {}

  public function send(): mixed {
    self::$sent[] = $this->label;
    return null;
  }
}
