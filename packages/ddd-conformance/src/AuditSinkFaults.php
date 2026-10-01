<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * Optional HostFixture seam for `audit.sink-fails` (wave-2 round-3 change
 * request CR-CC-1). A separate interface, not new HostFixture methods, so
 * host fixtures written against the ratified HostFixture keep compiling; a
 * fixture that does not implement it has the scenario skipped with the
 * request id.
 *
 * Map it onto the host's real audit sink: wp makes the next
 * `WpdbAuditSink::close()` fail (e.g. a `query` filter that fails the
 * UPDATE of `{prefix}_command_audit`), sf the DBAL sink's close statement.
 */
interface AuditSinkFaults {

  /**
   * Make the NEXT IAuditSink::close() throw. The act bracket closes the row
   * after the command's transaction, so the failure lands after commit.
   */
  public function failNextAuditClose(string $reason): void;
}
