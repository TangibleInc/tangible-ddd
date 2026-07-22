<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

use Closure;
use TangibleDDD\Infra\IDDDConfig;

final class ConsumerDefinition
{
    private readonly Closure $resolver;
    public readonly string $accent;

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        callable $resolver,
        public readonly bool $ghost = false,
        ?string $accent = null,
    ) {
        $this->resolver = Closure::fromCallable($resolver);
        $this->accent = is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}$/', $accent)
            ? $accent
            : self::fallbackAccent($key);
    }

    public function config(): ?IDDDConfig
    {
        $config = ($this->resolver)();
        return $config instanceof IDDDConfig ? $config : null;
    }

    private static function fallbackAccent(string $key): string
    {
        $palette = [
            '#2271b1',
            '#7a3e9d',
            '#005f73',
            '#6b5b00',
            '#4f46a5',
            '#2f6f3e',
            '#9a5b13',
            '#006d77',
            '#13795b',
            '#8f3f71',
            '#287271',
            '#b54708',
            '#3d5a80',
            '#6c5b7b',
            '#4b6b2a',
            '#8a4f14',
        ];
        $hash = (int) hexdec(substr(hash('sha256', $key), 0, 8));
        return $palette[$hash % count($palette)];
    }
}
