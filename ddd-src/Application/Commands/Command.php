<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Commands;

use TangibleDDD\Application\CQRS\CommandBusAware;
use Psr\Container\ContainerInterface;

use function TangibleDDD\WordPress\SelfConsumer\di;

/**
 * Base command for tangible-ddd's OWN (self-consumer) commands.
 *
 * Provides the container() the CommandBusAware trait needs — pointed at the
 * framework's self-consumer DI container (ddd-wordpress/self/index.php), so
 * `(new SomeCommand(...))->send()` dispatches through tangible_ddd's bus and
 * self-audits into wp_tangible_ddd_command_audit.
 */
abstract class Command implements ICommand {

    use CommandBusAware;

    protected static function container(): ContainerInterface {
        return di();
    }
}
