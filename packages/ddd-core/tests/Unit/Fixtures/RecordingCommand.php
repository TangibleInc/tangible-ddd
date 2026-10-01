<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Commands\ICommand;

/** A command whose send() records itself instead of touching a bus. */
final class RecordingCommand implements ICommand {

  /** @var list<self> */
  public static array $sent = [];

  /** @var list<?string> the deterministic command id hint pending at each send() */
  public static array $hints = [];

  public function __construct(public readonly string $label, public readonly mixed $data = null) {}

  /** @var null|\Closure(self): void runs inside send(), after recording (a synchronous handler) */
  public static ?\Closure $onSend = null;

  public function send(): mixed {
    self::$sent[] = $this;
    self::$hints[] = \TangibleDDD\Runtime\Ids\DeterministicCommandId::peek();
    if (self::$onSend !== null) {
      (self::$onSend)($this);
    }
    return null;
  }

  /** @return list<string> */
  public static function labels(): array {
    return array_map(static fn (self $c) => $c->label, self::$sent);
  }
}
