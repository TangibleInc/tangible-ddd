<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

final class WpDatabase implements Database
{
    /** @var array<string, bool> */
    private array $knownTables = [];

    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public static function fromGlobal(): self
    {
        global $wpdb;
        return new self($wpdb);
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function tableExists(string $table): bool
    {
        if (! array_key_exists($table, $this->knownTables)) {
            $like = $this->wpdb->esc_like($table);
            $found = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like));
            $this->knownTables[$table] = is_string($found) && $found === $table;
        }
        return $this->knownTables[$table];
    }

    public function escapeLike(string $value): string
    {
        return $this->wpdb->esc_like($value);
    }

    public function prepare(string $sql, array $args): string
    {
        return $args === [] ? $sql : $this->wpdb->prepare($sql, $args);
    }

    public function value(string $sql): mixed
    {
        return $this->wpdb->get_var($sql);
    }

    public function results(string $sql): array
    {
        $rows = $this->wpdb->get_results($sql, 'ARRAY_A') ?: [];
        return array_values(array_filter($rows, 'is_array'));
    }

    public function column(string $sql): array
    {
        return array_values($this->wpdb->get_col($sql) ?: []);
    }
}
