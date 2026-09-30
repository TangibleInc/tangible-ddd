<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * What ITransactionBoundary::run() does when a transaction is already open (X10).
 * Reject is the production default (a nested START TRANSACTION on MySQL
 * implicitly commits the outer one); Savepoint is an opt-in per boundary
 * instance, mostly for tests.
 */
enum NestedPolicy {
  case Reject;
  case Savepoint;
}
