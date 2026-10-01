<?php

namespace TangibleDDD\Application\Outbox;

use TangibleDDD\Infra\Exceptions\IncorrectUsageException;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;

/**
 * Configuration for the transactional outbox.
 *
 * Core value (register 1.4, X11): same final class, same constructor.
 * from_options() keeps resolving on this FQCN because shipped YAML names it
 * as a factory, but delegates to the host's IOutboxOptionsReader (ddd-wp
 * reads the WordPress options); from_array() is the host-neutral factory.
 */
final class OutboxConfig {

  public function __construct(
    public readonly int $batch_size = 50,
    public readonly int $max_attempts = 5,
    public readonly int $base_retry_delay_seconds = 60,
    public readonly float $retry_multiplier = 2.0,
    public readonly int $max_retry_delay_seconds = 3600,
    public readonly int $processor_interval_seconds = 30,
    public readonly int $lock_timeout_seconds = 300,
    public readonly string $action_scheduler_group = 'ddd-outbox',
    public readonly int $max_action_scheduler_payload_bytes = 50000,
    public readonly bool $route_large_payloads_to_external = false,
  ) {}

  /**
   * Create config from the host's settings for this consumer (WordPress
   * options on wp).
   *
   * @throws IncorrectUsageException when the host registered no IOutboxOptionsReader
   */
  public static function from_options(IDDDConfig $config): self {
    $reader = HostDefaults::get(IOutboxOptionsReader::class);
    if ($reader === null) {
      throw new IncorrectUsageException(
        'OutboxConfig::from_options() needs a host options reader (ddd-wp provides one at init); '
        . 'outside WordPress build OutboxConfig directly or with OutboxConfig::from_array().'
      );
    }
    return $reader->read($config);
  }

  /**
   * Named overrides of the constructor defaults, e.g. ['batch_size' => 10].
   *
   * @param array<string, int|float|string|bool> $values
   * @throws \InvalidArgumentException for a key that is not a constructor parameter
   */
  public static function from_array(array $values): self {
    $known = array_map(
      static fn (\ReflectionParameter $p) => $p->getName(),
      (new \ReflectionMethod(self::class, '__construct'))->getParameters()
    );
    $unknown = array_diff(array_keys($values), $known);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Unknown OutboxConfig keys: ' . implode(', ', $unknown));
    }

    $defaults = new self();
    $args = [];
    foreach ($known as $name) {
      $value = $values[$name] ?? $defaults->{$name};
      $args[$name] = match (get_debug_type($defaults->{$name})) {
        'int' => (int) $value,
        'float' => (float) $value,
        'bool' => (bool) $value,
        default => (string) $value,
      };
    }
    return new self(...$args);
  }
}
