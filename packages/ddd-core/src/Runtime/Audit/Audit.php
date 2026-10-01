<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * Per-command audit switch (D12), read by AttributeAuditPolicy.
 *
 *   #[Audit(false)]              no audit row for this command
 *   #[Audit(parameters: false)]  a row, but without the command's parameters
 *
 * Applies to the class and its subclasses (the nearest declaration wins).
 * It only switches the audit WRITE: the act bracket's nesting guard and the
 * other guards (post-seal event, re-raised fact) hold for every command.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Audit {

  public function __construct(
    public readonly bool $enabled = true,
    public readonly bool $parameters = true,
  ) {}
}
