<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Log\LoggerInterface;

/**
 * Adapts a PSR-3 logger to whatever a core runtime constructor takes for its
 * diagnostics: wave 1 takes `?\Closure(string): void`, wave 2 switches to
 * `?Psr\Log\LoggerInterface` (wave-1 notes, logging). Reading the parameter
 * type keeps the bundle's wiring correct on both sides of that change.
 *
 * @internal
 */
final class RuntimeLog {

  /**
   * @param class-string $class
   * @return LoggerInterface|\Closure(string): void|null
   */
  public static function argument(string $class, string $parameter, ?LoggerInterface $logger): LoggerInterface|\Closure|null {
    if ($logger === null) {
      return null;
    }
    $ctor = (new \ReflectionClass($class))->getConstructor();
    foreach ($ctor?->getParameters() ?? [] as $p) {
      if ($p->getName() !== $parameter) {
        continue;
      }
      $accepts = self::typeNames($p->getType());
      if (in_array(LoggerInterface::class, $accepts, true)) {
        return $logger;
      }
      if (in_array(\Closure::class, $accepts, true)) {
        return static function (string $message) use ($logger): void {
          $logger->warning($message);
        };
      }
    }
    return null;
  }

  /** @return list<string> */
  private static function typeNames(?\ReflectionType $type): array {
    if ($type instanceof \ReflectionNamedType) {
      return [$type->getName()];
    }
    if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
      $names = [];
      foreach ($type->getTypes() as $t) {
        array_push($names, ...self::typeNames($t));
      }
      return $names;
    }
    return [];
  }
}
