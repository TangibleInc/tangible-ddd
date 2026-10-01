<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use League\Tactician\Handler\Mapping\CommandToHandlerMapping;

/**
 * The bus's command → handler mapping: an explicit map first, the naming
 * convention (MapByNamingConvention over HandlerClassNameInflector) for
 * everything else. The map holds library commands whose namespace does not
 * fit the `Commands` → `CommandHandlers` convention, such as core's
 * stranded-process repairs (`Application\Process\Repair\*`, WP8-10).
 *
 * @internal
 */
final class ExplicitHandlerMapping implements CommandToHandlerMapping {

  /** @param array<class-string, class-string> $map command class → handler class (the locator key) */
  public function __construct(
    private readonly array $map,
    private readonly CommandToHandlerMapping $convention,
  ) {}

  public function getClassName(string $commandClassName): string {
    return $this->map[$commandClassName] ?? $this->convention->getClassName($commandClassName);
  }

  public function getMethodName(string $commandClassName): string {
    return isset($this->map[$commandClassName]) ? 'handle' : $this->convention->getMethodName($commandClassName);
  }
}
