<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Fakes\Dashboard;

use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class ScriptedDatabase implements Database
{
    /** @var list<array{sql: string, args: list<mixed>}> */
    public array $prepared = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var list<mixed> */
    public array $values = [];

    /** @var list<list<array<string, mixed>>> */
    public array $resultSets = [];

    /** @var list<list<mixed>> */
    public array $columns = [];

    public function __construct(private readonly string $tablePrefix = 'wp_')
    {
    }

    public function prefix(): string
    {
        return $this->tablePrefix;
    }

    public function escapeLike(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    /** @param array<mixed> $args */
    public function prepare(string $sql, array $args): string
    {
        $this->prepared[] = ['sql' => $sql, 'args' => array_values($args)];
        return $sql . ' /* prepared:' . count($this->prepared) . ' */';
    }

    public function value(string $sql): mixed
    {
        $this->queries[] = $sql;
        return array_shift($this->values);
    }

    public function results(string $sql): array
    {
        $this->queries[] = $sql;
        return array_shift($this->resultSets) ?? [];
    }

    public function column(string $sql): array
    {
        $this->queries[] = $sql;
        return array_shift($this->columns) ?? [];
    }
}
