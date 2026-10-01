<?php

declare(strict_types=1);

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

/**
 * Behaviour type → config class (TXP demand W2, wave 5): what
 * BaseBehaviourConfig::from_json() resolves a stored `type` through.
 *
 * Each host populates one at boot and provides it as
 * HostDefaults::provide(IBehaviourTypes::class, ...) (sf: from the
 * autoconfigured BaseBehaviourConfig subclasses; wp and pdo: the consumers'
 * register_type() calls), so a repair command or an operator view can read
 * a stored workflow before any handler service was built.
 *
 * Error behaviour: register() never throws and re-registering a type
 * replaces it; find() returns null for an unknown type.
 * Lifetime: boot-time, process-wide (one per host container).
 */
interface IBehaviourTypes {

  /** @param class-string<BaseBehaviourConfig> $class */
  public function register(string $type, string $class): void;

  /** @return class-string<BaseBehaviourConfig>|null */
  public function find(string $type): ?string;
}
