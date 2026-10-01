<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * D8: the audit row leaves this command property out entirely, e.g. a
 * binary request body that stays in-process (TXP IngestTrace). Read by
 * Redactor::redact_object().
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class NotAudited {
}
