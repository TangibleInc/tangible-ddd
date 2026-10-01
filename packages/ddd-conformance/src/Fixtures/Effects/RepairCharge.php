<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Effects;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;

/**
 * The explicit D1 repair (TXP's RepairStripeCustomer shape): its handler
 * calls IEffectJournal::invalidate(ChargeWidget::key_for(widget), reason)
 * inside its own transaction. `abort` makes the handler throw after the
 * invalidate, so the transaction rolls back with it.
 */
final class RepairCharge implements ICommand, ITransactionalCommand {

  public function __construct(
    public readonly string $widget_id,
    public readonly string $reason,
    public readonly bool $abort = false,
  ) {}

  public function send(): mixed {
    return EffectLedger::bus()->handle($this);
  }
}
