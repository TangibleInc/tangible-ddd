<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

use TangibleDDD\Infra\IDDDConfig;

/** IDDDConfig for inspecting tables left behind by an inactive consumer. */
final class PrefixOnlyConfig implements IDDDConfig
{
    public function __construct(
        private readonly string $consumerPrefix,
        private readonly string $tablePrefix,
    ) {
    }

    public function prefix(): string
    {
        return $this->consumerPrefix;
    }

    public function table(string $name): string
    {
        return $this->tablePrefix . $this->consumerPrefix . '_' . $name;
    }

    public function hook(string $name): string
    {
        return $this->consumerPrefix . '_' . $name;
    }

    public function as_group(string $name): string
    {
        return $this->consumerPrefix . '-' . $name;
    }

    public function option(string $name): string
    {
        return $this->consumerPrefix . '_' . $name;
    }

    public function domain_action(string $event_name): string
    {
        return $this->consumerPrefix . '_' . $event_name;
    }

    public function integration_action(string $event_name): string
    {
        return $this->consumerPrefix . '_integration_' . $event_name;
    }

    public function version(): string
    {
        return 'ghost';
    }
}
