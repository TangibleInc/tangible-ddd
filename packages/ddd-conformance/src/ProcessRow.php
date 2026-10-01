<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** A process row as the host stores it (ProcessHost::processRow()); read back without the lock. */
final class ProcessRow {

  public function __construct(
    public readonly int $id,
    /** the stored process class */
    public readonly string $processClass,
    public readonly string $status,
    public readonly int $stepIndex,
    public readonly int $version,
    /** uuid5(event_id, process_class) for a #[StartsOn] ignition, null for a manual start (register 3.8) */
    public readonly ?string $ignitionKey,
    public readonly ?string $ignitedByEventId,
  ) {}
}
