<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * Optional HostFixture seam for the CR sf-7 case of `cmd.commit-failure`
 * ("work swallows a statement error"; CR-W3CP-3). Without it that case is
 * not run (the COMMIT-failure case is).
 */
interface StatementErrors {

  /**
   * On the host connection, inside the transaction that is open, execute
   * ONE statement the database rejects (e.g. a duplicate primary key on the
   * scenario table) and throw the driver's error. On Postgres the
   * transaction is then aborted (25P02) and its COMMIT answers ROLLBACK;
   * MySQL and mem keep it usable.
   */
  public function fail_statement(): void;
}
