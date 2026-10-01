<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

use TangibleDDD\Runtime\ConsumerPrefix;
use TangibleDDD\Runtime\HostDefaults;

/**
 * Resolves the IOutboxAdministration a repair handler operates on: the one
 * it was constructed with, else the host's for the command's target prefix
 * (HostDefaults::for with a ConsumerPrefix identity; ddd-wp answers
 * WpdbOutboxAdministration), else \LogicException naming the port.
 *
 * @internal used by the four repair handlers
 */
final class OutboxAdministrationFor {

  public static function prefix(string $consumerPrefix, ?IOutboxAdministration $explicit = null): IOutboxAdministration {
    return $explicit
      ?? HostDefaults::for(IOutboxAdministration::class, new ConsumerPrefix($consumerPrefix))
      ?? throw new \LogicException(
        "No IOutboxAdministration for consumer \"$consumerPrefix\": construct the repair handler with one, or run on a host that provides one."
      );
  }
}
