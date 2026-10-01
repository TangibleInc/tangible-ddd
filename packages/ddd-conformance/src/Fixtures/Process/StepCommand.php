<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;

/**
 * A process step's command. send() needs no bus: it records itself with the
 * deterministic command id the runner sent it under (ProcessJournal), and
 * commits its effect row when the host bound one.
 */
final class StepCommand implements ICommand {

  public function __construct(
    public readonly string $label,
    public readonly string $widget_id = 'w-1',
  ) {}

  public function send(): mixed {
    ProcessJournal::sent($this, DeterministicCommandId::peek());
    return null;
  }
}
