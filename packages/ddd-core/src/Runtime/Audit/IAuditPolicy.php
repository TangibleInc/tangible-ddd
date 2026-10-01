<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * Which commands are audited, and with which parameters (register 3.9, D12).
 * The nesting guard (CommandDispatchedInsideCommand) is unconditional and
 * runs BEFORE the policy; a policy can only skip the write.
 *
 * Error behaviour: never throws. Lifetime: stateless.
 * Default (wave 4): AttributeAuditPolicy, which reads #[Audit(false)] /
 * #[Audit(parameters: false)] and optional class lists.
 */
interface IAuditPolicy {

  public function audits(object $command): bool;

  public function captureParameters(object $command): bool;
}
