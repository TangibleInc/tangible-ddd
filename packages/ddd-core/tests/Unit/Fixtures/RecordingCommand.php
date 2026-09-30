<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Commands\ICommand;

/** A command whose send() records itself instead of touching a bus. */
final class RecordingCommand implements ICommand {

  /** @var list<self> */
  public static array $sent = [];

  public function __construct(public readonly string $label, public readonly mixed $data = null) {}

  public function send(): mixed {
    self::$sent[] = $this;
    return null;
  }

  /** @return list<string> */
  public static function labels(): array {
    return array_map(static fn (self $c) => $c->label, self::$sent);
  }
}
