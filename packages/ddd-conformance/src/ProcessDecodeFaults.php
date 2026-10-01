<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * Optional seam for `decode.unknown-class` (wave 4, CR-W4C4-3; register 3.8
 * "Quarantine", R5). A separate interface, so HostFixture and ProcessHost
 * are unchanged; a fixture without it has the scenario skipped with the
 * request id. Requires ProcessHost.
 *
 * - forget_class(): rewrite the STORED class of process $processId
 *   to $missingClass (a class that does not exist), as if the class had
 *   been deleted or renamed by a deploy. Nothing else in the row changes.
 *   (mem: InMemoryProcessStore::corrupt_class(); SQL hosts: an
 *   UPDATE of the class column, and of the class inside the serialized
 *   state where the host stores one.)
 * - stored_status() / quarantine_reason(): read the row's status and
 *   quarantine_reason columns WITHOUT decoding the process (ProcessHost::
 *   process_row() may not be able to return an undecodable row).
 */
interface ProcessDecodeFaults {

  public function forget_class(int $processId, string $missingClass): void;

  public function stored_status(int $processId): ?string;

  public function quarantine_reason(int $processId): ?string;
}
