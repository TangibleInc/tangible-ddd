<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Module;

final class ModuleDefinition
{
    /**
     * @param list<class-string> $services
     * @param list<class-string> $processes
     * @param list<string> $bridged_services
     * @param list<class-string> $events
     * @param array<class-string, class-string> $handlers plain command =>
     *   paired ICommandHandler (two-class shape); terminal resolution stays
     *   in the module container per the consumer-module rules. Handler
     *   classes must also be listed in $services so the container can
     *   construct them.
     */
    public function __construct(
        public readonly string $host_prefix,
        public readonly string $namespace_root,
        public readonly string $transaction_service_id,
        public readonly array $services = [],
        public readonly array $processes = [],
        public readonly array $bridged_services = [],
        public readonly array $events = [],
        public readonly array $handlers = [],
    ) {
    }
}
