<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IAuditSink;

/**
 * IAuditSink decorator with a one-shot close failure, the mem side of
 * AuditSinkFaults (`audit.sink-fails`). A failed close() is not forwarded,
 * as when the sink's store is down.
 */
final class FaultInjectingAuditSink implements IAuditSink {

  private ?string $failNextClose = null;

  public function __construct(private readonly IAuditSink $inner) {}

  public function fail_next_close(string $reason): void {
    $this->failNextClose = $reason;
  }

  public function open(AuditOpen $r): void {
    $this->inner->open($r);
  }

  public function close(AuditClose $r): void {
    if ($this->failNextClose !== null) {
      $reason = $this->failNextClose;
      $this->failNextClose = null;
      throw new \RuntimeException($reason);
    }
    $this->inner->close($r);
  }
}
