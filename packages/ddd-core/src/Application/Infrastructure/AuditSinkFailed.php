<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Infrastructure;

/**
 * The audit sink threw (register 3.9, scenario `audit.sink-fails`). The
 * command's business outcome stands; this signal makes the lost or
 * incomplete audit row visible. Subject: the command id. Correlation: the
 * command's story; causation: the command itself (the act whose row failed).
 */
final class AuditSinkFailed extends InfrastructureEvent {

  /** @param 'open'|'close' $phase */
  public function __construct(
    string $command_id,
    ?string $correlation_id,
    public readonly string $phase,
    public readonly string $error,
  ) {
    parent::__construct($command_id, $correlation_id, $command_id, 'command');
  }

  public static function action(): string {
    return 'audit_sink_failed';
  }
}
