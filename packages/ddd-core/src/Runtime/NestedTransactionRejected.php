<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * A transaction was already open and the policy is Reject. Also thrown by
 * operations that must run outside any transaction (IOutboxStore::claim).
 */
final class NestedTransactionRejected extends \LogicException {}
