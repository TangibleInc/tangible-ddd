<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * Host-supplied default port implementations (register 1.3 R2).
 *
 * Legacy constructors in core keep their 0.6.5 arity and gain optional
 * trailing port parameters; a `null` argument resolves here. ddd-wp populates
 * this at init; it is empty everywhere else, so pdo and sf wire ports
 * explicitly.
 *
 * Lifetime: process-static and BOOT-TIME ONLY. RuntimeReset never clears it
 * (A F-18); only the test seam resetForTests() does.
 *
 * Error behaviour: provide() throws \InvalidArgumentException when the
 * implementation does not implement the named port; get() never throws and
 * returns null when nothing was provided.
 */
final class HostDefaults {

  /** @var array<class-string, object> */
  private static array $impls = [];

  /**
   * @template T of object
   * @param class-string<T> $port
   * @param T $impl
   */
  public static function provide(string $port, object $impl): void {
    if (!$impl instanceof $port) {
      throw new \InvalidArgumentException(sprintf('%s does not implement %s', get_class($impl), $port));
    }
    self::$impls[$port] = $impl;
  }

  /**
   * @template T of object
   * @param class-string<T> $port
   * @return T|null
   */
  public static function get(string $port): ?object {
    return self::$impls[$port] ?? null;
  }

  public static function has(string $port): bool {
    return isset(self::$impls[$port]);
  }

  /** Test seam only; production never clears host defaults. */
  public static function resetForTests(): void {
    self::$impls = [];
  }
}
