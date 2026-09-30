<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * Which commands are audited, and with which parameters (register 3.9, D12).
 * The nesting guard (CommandDispatchedInsideCommand) is unconditional and
 * runs BEFORE the policy; a policy can only skip the write.
 *
 * Error behaviour: never throws. Lifetime: stateless.
 * The #[Audit(false)] attribute read by the default policy lands in wave 4.
 */
interface IAuditPolicy {

  public function audits(object $command): bool;

  public function captureParameters(object $command): bool;
}
