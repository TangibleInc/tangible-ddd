<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IAuditSink;

/** Records audit rows; can throw on open or close (`audit.sink-fails`). */
final class InMemoryAuditSink implements IAuditSink {

  /** @var list<AuditOpen> */
  public array $opened = [];

  /** @var list<AuditClose> */
  public array $closed = [];

  public function __construct(
    private readonly ?\Throwable $failOnOpen = null,
    private readonly ?\Throwable $failOnClose = null,
  ) {}

  public function open(AuditOpen $r): void {
    if ($this->failOnOpen !== null) {
      throw $this->failOnOpen;
    }
    $this->opened[] = $r;
  }

  public function close(AuditClose $r): void {
    if ($this->failOnClose !== null) {
      throw $this->failOnClose;
    }
    $this->closed[] = $r;
  }
}
