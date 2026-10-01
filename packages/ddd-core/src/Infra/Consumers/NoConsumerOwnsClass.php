<?php

namespace TangibleDDD\Infra\Consumers;

/**
 * Thrown by ConsumerRegistry::owner_of() when no registered consumer's
 * namespace root contains the given class. Usually means the owning
 * plugin never called boot(), or the class lives outside any consumer.
 */
final class NoConsumerOwnsClass extends \RuntimeException {

  public static function for_class(string $class, array $known_roots): self {
    return new self(sprintf(
      'No registered consumer owns "%s" — did its plugin call boot()? Known namespace roots: %s',
      $class,
      $known_roots ? implode(', ', $known_roots) : '(none)',
    ));
  }
}
