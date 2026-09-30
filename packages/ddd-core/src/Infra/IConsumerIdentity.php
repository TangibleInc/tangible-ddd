<?php

declare(strict_types=1);

namespace TangibleDDD\Infra;

/**
 * The portable consumer identity (register 3.1, X6).
 *
 * `IDDDConfig` gains this as its parent in wave 2; portable core code types
 * against this interface, never against the WordPress-flavoured IDDDConfig.
 *
 * Error behaviour: both methods are pure accessors and never throw.
 * Lifetime: boot-time, process-static; registered once in ConsumerRegistry
 * and never reset per message.
 */
interface IConsumerIdentity {

  /** Stable, [a-z0-9_]+; namespaces tables, locks, keys and hooks. */
  public function prefix(): string;

  public function version(): string;
}
