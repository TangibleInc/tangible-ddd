<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\Billing\Process;

use TangibleDDD\Application\Process\Async;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;
use TangibleDDD\Symfony\Tests\Kernel\Billing\Commands\BillWidgetCommand;

/**
 * Consumer `bil`: a one-step process ignited by the app consumer's fact. It
 * lives in bil's process store and its #[Async] step runs through bil's wakeups.
 */
#[StartsOn(WidgetRegistered::class)]
final class InvoiceWidget extends LongProcess {

  public function __construct(public readonly string $widget_id) {
    parent::__construct(null);
  }

  public static function from_event(WidgetRegistered $event): ?static {
    return str_starts_with($event->widget_id, 'inv-') ? new static($event->widget_id) : null;
  }

  #[Async]
  protected function invoice(): Result {
    return new Result(commands: [new BillWidgetCommand('invoiced:' . $this->widget_id)]);
  }
}
