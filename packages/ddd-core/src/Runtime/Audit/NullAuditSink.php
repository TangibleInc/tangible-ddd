<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/** Discards audit rows (audit off, or hosts without an audit table). */
final class NullAuditSink implements IAuditSink {

  public function open(AuditOpen $r): void {}

  public function close(AuditClose $r): void {}
}
