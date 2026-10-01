<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Attribute;

/**
 * Marks a service as an integration listener (the add_action replacement:
 * discovered at compile time, never constructed at boot). The class has the
 * IntegrationTranslator shape: public event_class() and
 * translate(IIntegrationEvent): ?ICommand.
 *
 * `event` (optional) names the fact class or marker interface statically;
 * without it the bundle reads event_class() at compile time from an instance
 * made without its constructor, so event_class() must return a constant.
 * The subscriber priority is the core #[SubscriberPriority] (default 10).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsIntegrationListener {

  public function __construct(public readonly ?string $event = null) {}
}
