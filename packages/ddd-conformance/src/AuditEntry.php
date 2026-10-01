<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** One closed command-audit row as a scenario reads it back from any host. */
final class AuditEntry {

  /** @param 'success'|'error' $status */
  public function __construct(
    public readonly string $command_id,
    public readonly string $command_name,
    public readonly string $status,
    public readonly ?string $error_type,
  ) {}
}
