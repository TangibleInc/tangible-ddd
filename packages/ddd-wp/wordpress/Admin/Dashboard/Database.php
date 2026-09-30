<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

/** Small read-side port over the wpdb operations used by the dashboard. */
interface Database
{
    public function prefix(): string;

    public function tableExists(string $table): bool;

    public function escapeLike(string $value): string;

    /** @param array<mixed> $args */
    public function prepare(string $sql, array $args): string;

    public function value(string $sql): mixed;

    /** @return list<array<string, mixed>> */
    public function results(string $sql): array;

    /** @return list<mixed> */
    public function column(string $sql): array;
}
