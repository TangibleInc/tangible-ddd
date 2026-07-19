<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

use Closure;
use TangibleDDD\Infra\Config;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Infra\IDDDConfig;

final class ConsumerCatalog
{
    private readonly Closure $registeredConsumers;
    private readonly Closure $selfConfig;

    /** @var array<string, ConsumerDefinition>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Database $db,
        ?callable $registeredConsumers = null,
        ?callable $selfConfig = null,
    ) {
        $this->registeredConsumers = $registeredConsumers !== null
            ? Closure::fromCallable($registeredConsumers)
            : static fn (): array => function_exists('TangibleDDD\\WordPress\\consumers')
                ? \TangibleDDD\WordPress\consumers()
                : [];
        $this->selfConfig = $selfConfig !== null
            ? Closure::fromCallable($selfConfig)
            : static fn (): IDDDConfig => Config::for_wordpress();
    }

    /** @return array<string, ConsumerDefinition> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach (($this->registeredConsumers)() as $prefix => $handle) {
            if (! $handle instanceof ConsumerHandle) {
                continue;
            }
            $key = (string) $prefix;
            $out[$key] = new ConsumerDefinition(
                $key,
                $handle->prefix(),
                static fn (): IDDDConfig => $handle->config(),
            );
        }

        if ($out === []) {
            $out = $this->legacyConsumers();
        }

        $out['tangible_ddd'] ??= new ConsumerDefinition(
            'tangible_ddd',
            'tangible_ddd',
            $this->selfConfig,
        );

        foreach ($this->ghostPrefixes(array_keys($out)) as $prefix) {
            $out[$prefix] = new ConsumerDefinition(
                $prefix,
                $prefix,
                fn (): IDDDConfig => new PrefixOnlyConfig($prefix, $this->db->prefix()),
                true,
            );
        }

        return $this->cache = $out;
    }

    public function get(string $key): ?ConsumerDefinition
    {
        return $this->all()[$key] ?? null;
    }

    public function config(string $key): ?IDDDConfig
    {
        return $this->get($key)?->config();
    }

    public function prefix(string $key): ?string
    {
        return $this->get($key)?->label;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->all() as $key => $consumer) {
            $labels[$key] = $consumer->label . ($consumer->ghost ? ' (ghost)' : '');
        }
        return $labels;
    }

    /** @return array<string, ConsumerDefinition> */
    private function legacyConsumers(): array
    {
        return [
            'tangible_datastream' => new ConsumerDefinition(
                'tangible_datastream',
                'tangible_datastream',
                function (): ?IDDDConfig {
                    $class = '\\Tangible\\Datastream\\Infra\\DatastreamConfig';
                    return class_exists($class) ? new $class($this->db->prefix()) : null;
                },
            ),
            'tgbl_cred' => new ConsumerDefinition(
                'tgbl_cred',
                'tgbl_cred',
                static function (): ?IDDDConfig {
                    $class = '\\Tangible\\Cred\\Infra\\Config';
                    return class_exists($class) ? new $class() : null;
                },
            ),
        ];
    }

    /**
     * @param list<string> $known
     * @return list<string>
     */
    private function ghostPrefixes(array $known): array
    {
        $sql = $this->db->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
            [$this->db->escapeLike($this->db->prefix()) . '%\\_command_audit'],
        );

        $ghosts = [];
        foreach ($this->db->column($sql) as $table) {
            if (! is_string($table) || ! str_ends_with($table, '_command_audit')) {
                continue;
            }
            $prefix = substr($table, strlen($this->db->prefix()), -strlen('_command_audit'));
            if ($prefix !== '' && ! in_array($prefix, $known, true)) {
                $ghosts[] = $prefix;
            }
        }
        return $ghosts;
    }
}
