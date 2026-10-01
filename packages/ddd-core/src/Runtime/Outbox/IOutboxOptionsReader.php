<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Reads a consumer's OutboxConfig from host settings (register X11). ddd-wp
 * provides the WordPress-options reader at init; OutboxConfig::from_options()
 * delegates here through HostDefaults and throws IncorrectUsageException
 * when no reader was provided (every non-WordPress host builds OutboxConfig
 * directly or with from_array()).
 *
 * Error behaviour: never throws for missing settings (defaults apply).
 * Lifetime: boot time, process-static (HostDefaults).
 */
interface IOutboxOptionsReader {
  public function read(IDDDConfig $config): OutboxConfig;
}
