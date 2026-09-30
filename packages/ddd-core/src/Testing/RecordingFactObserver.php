<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\IFactObserver;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/** Records observed facts; optionally throws to prove observers cannot break publication. */
final class RecordingFactObserver implements IFactObserver {

  /** @var list<array{event: IIntegrationEvent, record: OutboxRecord}> */
  public array $observed = [];

  public function __construct(private readonly ?\Throwable $throw = null) {}

  public function observe(IIntegrationEvent $e, OutboxRecord $r): void {
    $this->observed[] = ['event' => $e, 'record' => $r];
    if ($this->throw !== null) {
      throw $this->throw;
    }
  }
}
