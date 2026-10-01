<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** A process row as the host stores it (ProcessHost::process_row()); read back without the lock. */
final class ProcessRow {

  public function __construct(
    public readonly int $id,
    /** the stored process class */
    public readonly string $process_class,
    public readonly string $status,
    public readonly int $step_index,
    public readonly int $version,
    /** uuid5(event_id, process_class) for a #[StartsOn] ignition, null for a manual start (register 3.8) */
    public readonly ?string $ignition_key,
    public readonly ?string $ignited_by,
  ) {}
}
