<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

/**
 * A pdo operator repair (PdoOperatorView::repair) refused by its guard: the
 * row is leased, gone, or the item does not offer the action (register 3.10,
 * C23). Nothing was written. Core refusals keep their own types
 * (OutboxAdministrationRefused, OutboxRowNotFound, ProcessNotStranded).
 */
final class PdoRepairRefused extends \RuntimeException {
}
