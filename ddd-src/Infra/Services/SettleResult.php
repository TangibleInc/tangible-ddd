<?php

namespace TangibleDDD\Infra\Services;

/**
 * What one settle did: how many passes it took, the outbox rows relayed and
 * the Action Scheduler actions run, and whether it ended because a pass
 * found nothing left (rather than by running out of passes).
 */
final class SettleResult {

  public function __construct(
    public readonly int $passes,
    public readonly int $outbox_rows,
    public readonly int $actions_run,
    public readonly bool $settled,
  ) {}

  /** @return array{passes: int, outbox_rows: int, actions_run: int, settled: bool} */
  public function to_array(): array {
    return [
      'passes' => $this->passes,
      'outbox_rows' => $this->outbox_rows,
      'actions_run' => $this->actions_run,
      'settled' => $this->settled,
    ];
  }
}
