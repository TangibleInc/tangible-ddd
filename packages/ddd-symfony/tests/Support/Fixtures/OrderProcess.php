<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Application\Process\Result;

/** A concrete process for store tests: two promoted constructor params (business_data). */
final class OrderProcess extends LongProcess {

  public function __construct(
    private readonly int $order_id,
    private readonly string $channel = 'web',
  ) {
    parent::__construct(null);
  }

  public static function from_event(PingFact $event): ?static {
    return new static($event->n);
  }

  public function orderId(): int {
    return $this->order_id;
  }

  public function channel(): string {
    return $this->channel;
  }

  /** What the runner does at start (initialize_lifecycle with reflected steps), for store tests. */
  public static function started(int $order_id, string $correlation = '0f6a5b8e-1d2c-4b3a-9e8f-7a6b5c4d3e2f'): self {
    $p = new self($order_id);
    $p->initialize_lifecycle($correlation, new ProcessSteps(['reserve', 'charge'], ['reserve' => 'release']));
    return $p;
  }

  protected function reserve(): Result {
    return new Result();
  }

  protected function charge(): Result {
    return new Result();
  }

  protected function release(): Result {
    return new Result();
  }
}
