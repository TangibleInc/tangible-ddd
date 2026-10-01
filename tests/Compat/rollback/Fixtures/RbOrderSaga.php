<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;

/**
 * Ignited by RbOrderPlaced; suspends on AwaitEvent(RbPaymentReceived,
 * order) with no alarm; settles on the payment. Written only with the
 * 0.6.2 process API, so N and every 0.6.x winner run the same class.
 */
#[StartsOn(RbOrderPlaced::class)]
#[Awaits(RbPaymentReceived::class)]
class RbOrderSaga extends LongProcess {

  public function __construct(public readonly string $order = '') {
    parent::__construct(null);
  }

  public static function from_event(RbOrderPlaced $event): ?self {
    return $event->order === 'declined' ? null : new self($event->order);
  }

  protected function open(): Result {
    RbJournal::mark("order:open:{$this->order}");
    return new Result(null, [], new AwaitEvent(RbPaymentReceived::class, ['order' => $this->order]));
  }

  protected function settle(mixed $payload, RbPaymentReceived $payment): Result {
    RbJournal::mark("order:settle:{$this->order}");
    return new Result();
  }
}
