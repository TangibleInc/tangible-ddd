<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Module;

use TangibleDDD\Domain\Events\IIntegrationEvent;

use function TangibleDDD\WordPress\integration_listener;

/** Keeps intentionally terminal scenario facts valid on the AS transport. */
final class ScenarioFactObserver
{
    public function register(ModuleDefinition $module): void
    {
        foreach ($module->events as $event_class) {
            integration_listener(
                $event_class,
                static fn (IIntegrationEvent $event) => null,
            );
        }
    }
}
