<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress;

/**
 * Opts one DDD listener into handler retries on WordPress (wave 5).
 *
 * A DDD-registered listener on wp gets ONE attempt by default, as in 0.6:
 * a throw is recorded in the delivery ledger as exhausted and is never
 * repeated. `#[Retries(n)]` gives that listener the first attempt plus n
 * redeliveries (backoff 30 s × 2^attempt), its on-exhausted compensation
 * (a D1 failure_command) after the last one. It is read off:
 *
 * - a listener class registered through SubscriptionRegistrar or
 *   integration_listener() (the IntegrationListener subclass);
 * - the callback of integration_action(): a closure
 *   (`#[Retries(2)] function (...) {}`), a method (its own attribute, then
 *   its class), an invokable object (its class) or a function.
 *
 * The consumer-wide opt-in is the option `{prefix}_ddd_delivery_attempts`;
 * the filter `tangible_ddd_delivery_attempts` has the last word (see
 * WpLedgeredDelivery::budget()). Process ignition and resume subscribers
 * are not listeners and keep the core budget whatever is declared.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final class Retries {

  public function __construct(public readonly int $count) {
    if ($count < 0) {
      throw new \InvalidArgumentException("Retries must not be negative, got $count");
    }
  }

  /** The delivery budget it declares: the first attempt plus the retries. */
  public function attempts(): int {
    return $this->count + 1;
  }

  /**
   * The #[Retries] of a listener class or object, or of a callable; null
   * when none is declared or the target cannot be reflected.
   *
   * @param callable|object|string $listener
   */
  public static function of(mixed $listener): ?self {
    try {
      foreach (self::reflections($listener) as $r) {
        $attrs = $r->getAttributes(self::class);
        if ($attrs !== []) {
          return $attrs[0]->newInstance();
        }
      }
    } catch (\ReflectionException) {
    }
    return null;
  }

  /** @return list<\ReflectionClass<object>|\ReflectionFunctionAbstract> most specific first */
  private static function reflections(mixed $listener): array {
    return match (true) {
      $listener instanceof \Closure => [new \ReflectionFunction($listener)],
      is_object($listener) => [new \ReflectionClass($listener)],
      is_array($listener) && count($listener) === 2 && is_string($listener[1]) && (is_object($listener[0]) || is_string($listener[0])) => [
        new \ReflectionMethod($listener[0], $listener[1]),
        new \ReflectionClass($listener[0]),
      ],
      is_string($listener) && str_contains($listener, '::') => [
        new \ReflectionMethod($listener),
        new \ReflectionClass(strstr($listener, '::', true)),
      ],
      is_string($listener) && class_exists($listener) => [new \ReflectionClass($listener)],
      is_string($listener) && function_exists($listener) => [new \ReflectionFunction($listener)],
      default => [],
    };
  }
}
