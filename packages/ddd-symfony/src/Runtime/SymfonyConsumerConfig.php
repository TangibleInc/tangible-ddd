<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;

/**
 * The one Symfony consumer (E S8: bounded contexts are namespaces, not
 * consumers), registered in ConsumerRegistry at Bundle::boot().
 *
 * It implements IDDDConfig only because ConsumerRegistry::add() still types
 * it in wave 1; once core widens that to IConsumerIdentity (register 3.1)
 * portable code uses only prefix()/version(). The WordPress vocabulary
 * methods are pure string derivations (no WordPress call): they keep any
 * portable code that still asks for a hook or table name deterministic.
 * namespace_root() is the duck-typed method ConsumerHandle reads to route
 * `send()` and `Event::prefix()` by namespace.
 */
final class SymfonyConsumerConfig implements IDDDConfig, IConsumerIdentity {

  /** What an absent, null or empty `consumer.version` means (L4). */
  public const DEFAULT_VERSION = '0.0.0';

  private readonly string $version;

  /**
   * @param string|null $version null or '' (e.g. an unset `%env(default::APP_VERSION)%`,
   *   which resolves at runtime, after config processing) is DEFAULT_VERSION
   */
  public function __construct(
    private readonly string $prefix,
    private readonly string $namespaceRoot,
    ?string $version = self::DEFAULT_VERSION,
    private readonly string $tablePrefix = '',
  ) {
    $this->version = self::normalise_version($version);
    if (!preg_match('/^[a-z0-9_]+$/', $prefix)) {
      throw new \InvalidArgumentException("Consumer prefix '$prefix' must match [a-z0-9_]+");
    }
    if (trim($namespaceRoot, '\\') === '') {
      throw new \InvalidArgumentException('The consumer namespace root must not be empty');
    }
  }

  public static function normalise_version(?string $version): string {
    return $version === null || $version === '' ? self::DEFAULT_VERSION : $version;
  }

  public function prefix(): string {
    return $this->prefix;
  }

  public function version(): string {
    return $this->version;
  }

  public function namespace_root(): string {
    return trim($this->namespaceRoot, '\\');
  }

  public function table(string $name): string {
    return $this->tablePrefix . $name;
  }

  public function hook(string $name): string {
    return $this->prefix . '_' . $name;
  }

  public function as_group(string $name): string {
    return str_replace('_', '-', $this->prefix) . '-' . $name;
  }

  public function option(string $name): string {
    return $this->prefix . '_' . $name;
  }

  public function domain_action(string $event_name): string {
    return $this->prefix . '_domain_' . $event_name;
  }

  public function integration_action(string $event_name): string {
    return $this->prefix . '_integration_' . $event_name;
  }
}
