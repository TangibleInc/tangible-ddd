<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * Where command audit rows go (register 3.9). wp: WpdbAuditSink.
 *
 * Error behaviour: MAY throw. A failure after the domain commit is caught
 * by CorrelationMiddleware, logged and emitted as a signal; the business
 * outcome stays committed (`audit.sink-fails`).
 *
 * Connection rules: open() runs before the command's transaction; close()
 * after it (commit or rollback), so an audit row survives a rolled-back
 * command.
 */
interface IAuditSink {

  public function open(AuditOpen $r): void;

  public function close(AuditClose $r): void;
}
