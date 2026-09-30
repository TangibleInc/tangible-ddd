<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/** An ITransactionalCommand was dispatched with no boundary configured; thrown before the handler runs. */
final class NoTransactionBoundary extends \LogicException {}
