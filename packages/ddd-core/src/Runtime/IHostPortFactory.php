<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Infra\IConsumerIdentity;

/**
 * Per-consumer host defaults (CR-SP-2; register 1.3 R2).
 *
 * HostDefaults holds ONE implementation per port, which fits process-wide
 * ports (clock, lock, transaction boundary, actor). Some ports are per
 * consumer, because their storage is: the audit sink writes
 * `{prefix}_command_audit`, the touches observer `{prefix}_touches`, the
 * process store and the wakeup scheduler adapt the IProcessRepository /
 * IDDDConfig a 0.6 constructor was given. A legacy constructor that resolves
 * such a port asks this factory first, through HostDefaults::for().
 *
 * create() returns null when this host has no per-consumer form of $port
 * (the caller then falls back to HostDefaults::get($port), then to its core
 * default). $legacy is the 0.6 collaborator the caller holds and wants
 * adapted (an IProcessRepository for IProcessStore), or null.
 *
 * Error behaviour: never throws for a port it does not know (returns null);
 * may throw \InvalidArgumentException for a $legacy it cannot adapt.
 * Lifetime: boot time, process-static, like HostDefaults. ddd-wp provides it.
 */
interface IHostPortFactory {

  /**
   * @template T of object
   * @param class-string<T> $port
   * @return T|null
   */
  public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object;
}
