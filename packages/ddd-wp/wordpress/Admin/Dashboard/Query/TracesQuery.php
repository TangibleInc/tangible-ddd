<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class TracesQuery
{
    public function __construct(
        private readonly IDDDConfig $config,
        private readonly Database $db,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 60): array
    {
        $audit = $this->config->table('command_audit');
        $outbox = $this->config->table('integration_outbox');
        $processes = $this->config->table('long_processes');
        $workflows = $this->config->table('behaviour_workflows');
        $fiveMinutesAgo = gmdate('Y-m-d H:i:s', time() - 300);

        $rows = $this->db->results($this->db->prepare(
            "SELECT correlation_id,
                    MIN(started_at) AS first_at,
                    MAX(started_at) AS last_at,
                    COUNT(*) AS spans,
                    SUM(status='error') AS errs
             FROM `{$audit}`
             GROUP BY correlation_id
             HAVING MAX(started_at) >= %s
             ORDER BY MAX(started_at) DESC
             LIMIT %d",
            [$fiveMinutesAgo, $limit],
        ));
        if ($rows === []) {
            return [];
        }

        $correlations = array_column($rows, 'correlation_id');
        $placeholders = implode(',', array_fill(0, count($correlations), '%s'));
        $rootRows = $this->db->results($this->db->prepare(
            "SELECT correlation_id, command_name,
                    (causation_id IS NULL) AS is_root,
                    started_at
             FROM `{$audit}`
             WHERE correlation_id IN ({$placeholders})
             ORDER BY correlation_id, (causation_id IS NULL) DESC, started_at ASC",
            $correlations,
        ));
        $roots = [];
        foreach ($rootRows as $root) {
            $correlation = $root['correlation_id'];
            $roots[$correlation] ??= $root['command_name'];
        }

        $workflowAlive = $this->correlationSet(
            "SELECT DISTINCT correlation_id FROM `{$workflows}` "
            . "WHERE is_complete=0 AND is_failed=0 AND correlation_id IN ({$placeholders})",
            $correlations,
        );
        $outboxAlive = $this->correlationSet(
            "SELECT DISTINCT correlation_id FROM `{$outbox}` "
            . "WHERE status IN ('pending','processing') AND correlation_id IN ({$placeholders})",
            $correlations,
        );
        $processAlive = $this->correlationSet(
            "SELECT DISTINCT correlation_id FROM `{$processes}` "
            . "WHERE status IN ('suspended','scheduled') AND correlation_id IN ({$placeholders})",
            $correlations,
        );

        $eventCounts = $this->countsByCorrelation($outbox, $placeholders, $correlations);
        $workflowCounts = $this->countsByCorrelation($workflows, $placeholders, $correlations);
        $processCounts = $this->countsByCorrelation($processes, $placeholders, $correlations);

        $output = [];
        foreach ($rows as $row) {
            $correlation = $row['correlation_id'];
            $parts = explode('\\', (string) ($roots[$correlation] ?? ''));
            $output[] = [
                'correlation_id' => $correlation,
                'first_at' => $row['first_at'],
                'last_at' => $row['last_at'],
                'spans' => (int) $row['spans'],
                'errs' => (int) $row['errs'],
                'events' => $eventCounts[$correlation] ?? 0,
                'workflows' => $workflowCounts[$correlation] ?? 0,
                'processes' => $processCounts[$correlation] ?? 0,
                'dur_ms' => max(
                    0,
                    (strtotime((string) $row['last_at'] . ' UTC') - strtotime((string) $row['first_at'] . ' UTC')) * 1000,
                ),
                'root_command' => end($parts) ?: '',
                'in_progress' => isset($workflowAlive[$correlation])
                    || isset($outboxAlive[$correlation])
                    || isset($processAlive[$correlation]),
            ];
        }
        return $output;
    }

    /**
     * @param list<mixed> $correlations
     * @return array<string, true>
     */
    private function correlationSet(string $sql, array $correlations): array
    {
        $set = [];
        try {
            foreach ($this->db->results($this->db->prepare($sql, $correlations)) as $row) {
                $set[$row['correlation_id']] = true;
            }
        } catch (\Throwable) {
            // Optional operational tables can be absent on older consumers.
        }
        return $set;
    }

    /**
     * @param list<mixed> $correlations
     * @return array<string, int>
     */
    private function countsByCorrelation(string $table, string $placeholders, array $correlations): array
    {
        $counts = [];
        try {
            $sql = "SELECT correlation_id, COUNT(*) n FROM `{$table}` "
                . "WHERE correlation_id IN ({$placeholders}) GROUP BY correlation_id";
            foreach ($this->db->results($this->db->prepare($sql, $correlations)) as $row) {
                $counts[$row['correlation_id']] = (int) $row['n'];
            }
        } catch (\Throwable) {
            // Optional operational tables can be absent on older consumers.
        }
        return $counts;
    }
}
