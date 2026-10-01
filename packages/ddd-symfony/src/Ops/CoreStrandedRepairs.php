<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

/**
 * The process layer's repairs as core commands (wave3-notes WP8-10): core
 * ships `ResumeStrandedProcess` / `FailStrandedProcess` in wave 4, and
 * `ddd:ops:stranded --resume|--fail` dispatches them through the command
 * bus instead of repairing inline.
 *
 * Until core-process merges, the classes do not exist: available() is
 * false and StrandedCommand keeps its inline repair (Continue intent /
 * locked, version-fenced fail). The commands are built from their
 * constructors: the first parameter gets the process id; a parameter named
 * `reason` gets the operator's reason. If core settles on other class names,
 * RESUME / FAIL are the one place to change.
 */
final class CoreStrandedRepairs {

  public const RESUME = 'TangibleDDD\\Application\\Process\\ResumeStrandedProcess';
  public const FAIL = 'TangibleDDD\\Application\\Process\\FailStrandedProcess';

  /** @var \Closure(object): mixed */
  private readonly \Closure $dispatch;

  /** @param callable(object): mixed $dispatch e.g. [CommandBus, 'handle'] */
  public function __construct(
    callable $dispatch,
    private readonly string $resumeClass = self::RESUME,
    private readonly string $failClass = self::FAIL,
  ) {
    $this->dispatch = \Closure::fromCallable($dispatch);
  }

  public function available(): bool {
    return class_exists($this->resumeClass) && class_exists($this->failClass);
  }

  public function resume(int $processId): void {
    ($this->dispatch)($this->build($this->resumeClass, $processId, null));
  }

  public function fail(int $processId, string $reason): void {
    ($this->dispatch)($this->build($this->failClass, $processId, $reason));
  }

  private function build(string $class, int $processId, ?string $reason): object {
    if (!class_exists($class)) {
      throw new \LogicException("$class does not exist (core's stranded repairs are not installed).");
    }
    $params = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
    if ($params === []) {
      throw new \LogicException("$class takes no process id.");
    }
    $args = [$params[0]->getName() => $processId];
    foreach (array_slice($params, 1) as $param) {
      if ($param->getName() === 'reason' && $reason !== null) {
        $args['reason'] = $reason;
      }
    }
    return new $class(...$args);
  }
}
