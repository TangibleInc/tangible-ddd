<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;

/** Records emitted signals (e.g. `audit.sink-fails` asserts one was emitted). */
final class RecordingSignalDispatcher implements IInfrastructureSignalDispatcher {

  /** @var list<array{event: IInfrastructureEvent, consumer: IConsumerIdentity}> */
  public array $emitted = [];

  public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void {
    $this->emitted[] = ['event' => $e, 'consumer' => $c];
  }
}
