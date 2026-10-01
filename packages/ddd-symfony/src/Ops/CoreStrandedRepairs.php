<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

/**
 * The process layer's repairs as core commands (wave3-notes WP8-10, core
 * CR-W4P-4): `ddd:ops:stranded --resume|--fail` dispatches core's
 * `Application\Process\Repair\ResumeStrandedProcess` /
 * `FailStrandedProcess` on the bundle's command bus instead of repairing
 * inline. The core handlers guard (zero-wait process lock, the row must be
 * in find_stranded(), optional expected version) and refuse with
 * ProcessNotStranded.
 *
 * The commands are built from their constructors by parameter name:
 * `consumer_prefix` gets this consumer's prefix, `process_id` (or
 * `processId`, else the first parameter) the process id, `reason` the
 * operator's reason. When the classes do not exist (an older core),
 * available() is false and StrandedCommand keeps its inline repair.
 */
final class CoreStrandedRepairs {

  public const RESUME = 'TangibleDDD\\Application\\Process\\Repair\\ResumeStrandedProcess';
  public const FAIL = 'TangibleDDD\\Application\\Process\\Repair\\FailStrandedProcess';

  /** @var \Closure(object): mixed */
  private readonly \Closure $dispatch;

  /** @param callable(object): mixed $dispatch e.g. [CommandBus, 'handle'] */
  public function __construct(
    callable $dispatch,
    private readonly string $resumeClass = self::RESUME,
    private readonly string $failClass = self::FAIL,
    private readonly string $consumerPrefix = '',
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

    $names = array_map(static fn (\ReflectionParameter $p) => $p->getName(), $params);
    $idParam = array_values(array_intersect(['process_id', 'processId'], $names))[0] ?? $names[0];
    $args = [$idParam => $processId];
    if (in_array('consumer_prefix', $names, true)) {
      $args['consumer_prefix'] = $this->consumerPrefix;
    }
    if ($reason !== null && in_array('reason', $names, true)) {
      $args['reason'] = $reason;
    }
    return new $class(...$args);
  }
}
