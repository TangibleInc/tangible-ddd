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
 * implementation does not implement the named port; get() returns null when
 * nothing was provided and throws only what a miss resolver throws.
 *
 * Per-consumer ports (audit sink, fact observer, process store, wakeup
 * scheduler) resolve through for(), which asks the host's IHostPortFactory
 * first (CR-SP-2).
 *
 * Late hosts: a host whose init can run before its platform is up (ddd-wp
 * is included from vendor/autoload.php, before a test bootstrap loads
 * WordPress or its stubs) registers a miss resolver with onMiss(). get() and
 * for() call it when a port has no implementation, at most once at a time
 * (re-entrant misses during resolution return null), then read the port
 * again. The resolver decides whether it can provide anything yet and may
 * unregister itself; core never knows which platform it stands for. has()
 * reports only what was provided and never resolves.
 */
final class HostDefaults {

  /** @var array<class-string, object> */
  private static array $impls = [];

  /** @var (\Closure(class-string): void)|null */
  private static ?\Closure $missResolver = null;

  private static bool $resolving = false;

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
    return self::$impls[$port] ?? self::resolveMiss($port);
  }

  /**
   * Register (or, with null, remove) the host's miss resolver; see the class
   * doc. It receives the missed port and provides whatever it can through
   * provide(). Boot-time wiring like provide(); resetForTests() removes it.
   *
   * @param (callable(class-string): void)|null $resolver
   */
  public static function onMiss(?callable $resolver): void {
    self::$missResolver = $resolver === null ? null : \Closure::fromCallable($resolver);
  }

  /**
   * @param class-string $port
   */
  private static function resolveMiss(string $port): ?object {
    if (self::$missResolver === null || self::$resolving) {
      return null;
    }
    self::$resolving = true;
    try {
      (self::$missResolver)($port);
    } finally {
      self::$resolving = false;
    }
    return self::$impls[$port] ?? null;
  }

  public static function has(string $port): bool {
    return isset(self::$impls[$port]);
  }

  /**
   * Per-consumer resolution (CR-SP-2): the host's IHostPortFactory form of
   * $port for $consumer (adapting $legacy when given), else get($port).
   * Never throws for an unknown port; returns null when nothing was provided.
   *
   * @template T of object
   * @param class-string<T> $port
   * @return T|null
   */
  public static function for(string $port, \TangibleDDD\Infra\IConsumerIdentity $consumer, ?object $legacy = null): ?object {
    $factory = self::get(IHostPortFactory::class);
    if ($factory instanceof IHostPortFactory) {
      $impl = $factory->create($port, $consumer, $legacy);
      if ($impl !== null) {
        if (!$impl instanceof $port) {
          throw new \UnexpectedValueException(sprintf('%s::create(%s) returned a %s', get_class($factory), $port, get_class($impl)));
        }
        return $impl;
      }
    }
    return self::get($port);
  }

  /**
   * Test seam only; production never clears host defaults. Also removes the
   * miss resolver, so a test that provides a partial set is not topped up.
   */
  public static function resetForTests(): void {
    self::$impls = [];
    self::$missResolver = null;
  }
}
