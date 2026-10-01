<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Commands;

use TangibleDDD\Application\CQRS\CommandBusAware;

/**
 * Base command for tangible-ddd's OWN (self-consumer) commands: replay,
 * discard, retry, purge.
 *
 * Core form (register 1.4): no container() override. `->send()` resolves
 * the bus like every other command, through ConsumerRegistry::owner_of():
 * on WordPress ddd-wp registers the self-consumer (prefix `tangible_ddd`,
 * namespace root `TangibleDDD\Application\Commands`) at plugins_loaded:21,
 * once its container is built, so these commands keep dispatching through
 * tangible_ddd's own bus and self-audit into wp_tangible_ddd_command_audit.
 * Elsewhere a host registers whichever consumer should run them; an
 * unregistered host fails loudly with NoConsumerOwnsClass.
 */
abstract class Command implements ICommand {

    use CommandBusAware;
}
