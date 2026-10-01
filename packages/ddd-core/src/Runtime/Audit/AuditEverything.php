<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/** Wave-1 default IAuditPolicy: audit every command with (redacted) parameters. */
final class AuditEverything implements IAuditPolicy {

  public function audits(object $command): bool {
    return true;
  }

  public function captures_parameters(object $command): bool {
    return true;
  }
}
