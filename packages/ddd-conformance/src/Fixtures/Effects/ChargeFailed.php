<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Effects;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;

/** ChargeWidget's failure_command(): its handler (per scenario) commits `charge-failed:{widget}`. */
final class ChargeFailed implements ICommand, ITransactionalCommand {

  public function __construct(
    public readonly string $widget_id,
    public readonly string $reason,
  ) {}

  public function send(): mixed {
    EffectLedger::$failure_sends[] = DeterministicCommandId::peek();
    return EffectLedger::bus()->handle($this);
  }
}
