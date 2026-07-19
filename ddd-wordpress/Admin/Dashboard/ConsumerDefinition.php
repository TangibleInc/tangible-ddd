<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

use Closure;
use TangibleDDD\Infra\IDDDConfig;

final class ConsumerDefinition
{
    private readonly Closure $resolver;

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        callable $resolver,
        public readonly bool $ghost = false,
    ) {
        $this->resolver = Closure::fromCallable($resolver);
    }

    public function config(): ?IDDDConfig
    {
        $config = ($this->resolver)();
        return $config instanceof IDDDConfig ? $config : null;
    }
}
