<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class MetricsQuery
{
    public function __construct(
        private readonly IDDDConfig $config,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $audit = $this->config->table('command_audit');
        $outbox = $this->config->table('integration_outbox');
        $deadLetters = $this->config->table('integration_dlq');
        $processes = $this->config->table('long_processes');
        $workflows = $this->config->table('behaviour_workflows');
        $sinceDay = gmdate('Y-m-d H:i:s', time() - 86400);
        $sinceMinute = gmdate('Y-m-d H:i:s', time() - 60);

        $throughputMinute = $this->countPrepared(
            "SELECT COUNT(*) FROM `{$audit}` WHERE started_at>=%s",
            [$sinceMinute],
        );
        $errorsMinute = $this->countPrepared(
            "SELECT COUNT(*) FROM `{$audit}` WHERE started_at>=%s AND status='error'",
            [$sinceMinute],
        );
        $commandsDay = $this->countPrepared(
            "SELECT COUNT(*) FROM `{$audit}` WHERE started_at>=%s",
            [$sinceDay],
        );
        $errorsDay = $this->countPrepared(
            "SELECT COUNT(*) FROM `{$audit}` WHERE started_at>=%s AND status='error'",
            [$sinceDay],
        );
        $average = (int) round((float) $this->db->value($this->db->prepare(
            "SELECT AVG(duration_ms) FROM `{$audit}` WHERE started_at>=%s",
            [$sinceDay],
        )));
        $p95 = 0;
        if ($commandsDay > 0) {
            $offset = min((int) floor($commandsDay * 0.95), $commandsDay - 1);
            $p95 = (int) $this->db->value($this->db->prepare(
                "SELECT duration_ms FROM `{$audit}` WHERE started_at>=%s "
                . 'ORDER BY duration_ms ASC LIMIT 1 OFFSET %d',
                [$sinceDay, $offset],
            ));
        }

        $failed = $this->db->results(
            "SELECT command_name,correlation_id,started_at FROM `{$audit}` "
            . "WHERE status='error' ORDER BY started_at DESC LIMIT 6",
        );
        $dead = $this->db->results(
            "SELECT id,event_type,correlation_id,final_error,moved_at FROM `{$deadLetters}` "
            . 'ORDER BY moved_at DESC LIMIT 6',
        );
        $stuck = $this->db->results(
            "SELECT id,process_class,status,waiting_for,updated_at FROM `{$processes}` "
            . "WHERE status IN ('suspended','scheduled') ORDER BY updated_at DESC LIMIT 6",
        );
        $top = $this->db->results($this->db->prepare(
            "SELECT command_name, COUNT(*) AS n, SUM(status='error') AS errs FROM `{$audit}` "
            . 'WHERE started_at>=%s GROUP BY command_name ORDER BY n DESC LIMIT 8',
            [$sinceDay],
        ));

        $tableNames = array_map(fn (string $name): string => $this->config->table($name), [
            'command_audit',
            'integration_outbox',
            'integration_dlq',
            'long_processes',
            'behaviour_workflows',
            'behaviour_workflow_items',
        ]);
        $placeholders = implode(',', array_fill(0, count($tableNames), '%s'));
        $storage = $this->db->results($this->db->prepare(
            "SELECT table_name AS t, table_rows AS row_count, (data_length+index_length) AS bytes
             FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name IN ({$placeholders})
             ORDER BY bytes DESC",
            $tableNames,
        ));

        $outboxPending = (int) $this->db->value(
            "SELECT COUNT(*) FROM `{$outbox}` WHERE status IN ('pending','processing')",
        );
        $deadLetterDepth = (int) $this->db->value("SELECT COUNT(*) FROM `{$deadLetters}`");
        $inFlight = (int) $this->db->value(
            "SELECT COUNT(*) FROM `{$audit}` WHERE status='in_progress'",
        );
        $inFlight += (int) $this->db->value(
            "SELECT COUNT(*) FROM `{$workflows}` WHERE is_complete=0 AND is_failed=0",
        );
        try {
            $inFlight += (int) $this->db->value(
                "SELECT COUNT(*) FROM `{$processes}` WHERE status='suspended'",
            );
        } catch (\Throwable) {
            // Older consumers may not have long_processes.
        }

        $oldestOutboxAge = 0;
        if ($outboxPending > 0) {
            $age = $this->db->value(
                "SELECT TIMESTAMPDIFF(SECOND, MIN(COALESCE(scheduled_at, created_at)), NOW()) "
                . "FROM `{$outbox}` WHERE status IN ('pending','processing')",
            );
            $oldestOutboxAge = max(0, (int) $age);
        }

        return [
            'throughput_1m' => $throughputMinute,
            'errors_1m' => $errorsMinute,
            'commands_24h' => $commandsDay,
            'errors_24h' => $errorsDay,
            'success_rate' => $commandsDay ? round(($commandsDay - $errorsDay) / $commandsDay * 100, 1) : 100.0,
            'avg_ms' => $average,
            'p95_ms' => $p95,
            'outbox_pending' => $outboxPending,
            'outbox_oldest_age_s' => $oldestOutboxAge,
            'dlq_depth' => $deadLetterDepth,
            'in_flight' => $inFlight,
            'proc_active' => (int) $this->db->value(
                "SELECT COUNT(*) FROM `{$processes}` WHERE status IN ('pending','running','scheduled')",
            ),
            'proc_suspended' => (int) $this->db->value(
                "SELECT COUNT(*) FROM `{$processes}` WHERE status='suspended'",
            ),
            'failed' => $failed,
            'dead' => $dead,
            'stuck' => $stuck,
            'top_commands' => array_map(static fn (array $row): array => [
                'name' => $row['command_name'],
                'n' => (int) $row['n'],
                'errs' => (int) $row['errs'],
            ], $top),
            'storage' => array_map(static fn (array $row): array => [
                't' => $row['t'],
                'rows' => (int) $row['row_count'],
                'bytes' => (int) $row['bytes'],
            ], $storage),
        ];
    }

    /** @param list<mixed> $args */
    private function countPrepared(string $sql, array $args): int
    {
        return (int) $this->db->value($this->db->prepare($sql, $args));
    }
}
