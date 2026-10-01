<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Support;

/**
 * Resolves a TARGET consumer's table name from its bare prefix.
 *
 * Framework operational commands self-audit into tangible_ddd's own
 * command_audit, but they OPERATE on a target consumer's tables (e.g.
 * tangible_datastream's integration_dlq). The bare prefix is validated to a
 * strict charset before being interpolated into a table identifier.
 */
final class ConsumerTables {

    public static function name(string $prefix, string $table): string {
        if (! preg_match('/^[a-z0-9_]+$/', $prefix)) {
            throw new \InvalidArgumentException("Invalid consumer prefix: {$prefix}");
        }
        if (! preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        global $wpdb;
        return $wpdb->prefix . $prefix . '_' . $table;
    }
}
