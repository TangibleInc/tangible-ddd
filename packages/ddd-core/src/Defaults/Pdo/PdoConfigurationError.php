<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

/**
 * The host-supplied connection is configured in a way the pdo adapters cannot
 * work with safely (PDO::ATTR_ERRMODE other than ERRMODE_EXCEPTION, C 7.1),
 * or the schema the host applied does not match (SchemaCheck).
 */
final class PdoConfigurationError extends \LogicException {}
