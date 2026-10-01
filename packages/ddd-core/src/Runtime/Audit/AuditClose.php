<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/** The audit row finalised at bracket CLOSE (mirrors command_audit_finalise). */
final class AuditClose {

  /**
   * @param 'success'|'error' $status
   * @param list<array{name: string, reactions: array}> $events published facts with their reactions
   * @param array{type: string, message: string, code: int}|null $error
   */
  public function __construct(
    public readonly string $command_id,
    public readonly string $status,
    public readonly int $duration_ms,
    public readonly int $peak_memory_bytes,
    public readonly array $events,
    public readonly ?array $error,
  ) {
    if (!in_array($status, ['success', 'error'], true)) {
      throw new \InvalidArgumentException("Audit status must be 'success' or 'error', got '$status'");
    }
  }
}
