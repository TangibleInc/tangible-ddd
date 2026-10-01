<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * D8: the audit row masks this command property (last four characters
 * kept, as for the built-in sensitive keys; a binary value becomes
 * `[secret]`). Read by Redactor::redact_object().
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Sensitive {
}
