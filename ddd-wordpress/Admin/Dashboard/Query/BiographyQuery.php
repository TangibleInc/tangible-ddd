<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class BiographyQuery
{
    public function __construct(
        private readonly IDDDConfig $config,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function recent(array $filters): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $touches = $this->config->table('touches');
        if (! $this->db->tableExists($touches)) {
            return $this->emptyRecent($page, $perPage);
        }

        $where = ['1=1'];
        $params = [];
        if (! empty($filters['search'])) {
            $like = '%' . $this->db->escapeLike((string) $filters['search']) . '%';
            $where[] = '(aggregate LIKE %s OR aggregate_id LIKE %s)';
            array_push($params, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM (SELECT 1 FROM `{$touches}` "
            . "WHERE {$whereSql} GROUP BY aggregate, aggregate_id) biographies";
        $total = (int) $this->db->value($this->db->prepare($countSql, $params));

        $pageSql = "SELECT aggregate,aggregate_id,COUNT(*) touch_count,MIN(version) first_version,"
            . "MAX(version) last_version,MIN(occurred_at) first_at,MAX(occurred_at) last_at,"
            . "SUBSTRING_INDEX(GROUP_CONCAT(op ORDER BY version DESC,id DESC SEPARATOR ','), ',', 1) last_op "
            . "FROM `{$touches}` WHERE {$whereSql} GROUP BY aggregate, aggregate_id "
            . 'ORDER BY last_at DESC, aggregate ASC, aggregate_id ASC LIMIT %d OFFSET %d';
        $rows = $this->db->results($this->db->prepare($pageSql, [...$params, $perPage, $offset]));
        foreach ($rows as &$row) {
            foreach (['touch_count', 'first_version', 'last_version'] as $column) {
                $row[$column] = (int) ($row[$column] ?? 0);
            }
        }
        unset($row);

        return [
            'available' => true,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /** @return array<string, mixed> */
    public function read(string $aggregate, string $aggregateId): array
    {
        $touches = $this->config->table('touches');
        if (! $this->db->tableExists($touches)) {
            return $this->emptyBiography($aggregate, $aggregateId);
        }

        $audit = $this->config->table('command_audit');
        $outbox = $this->config->table('integration_outbox');
        $columns = 't.id,t.aggregate,t.aggregate_id,t.op,t.version,t.event_name,t.event_id,'
            . 't.command_id,t.correlation_id,t.occurred_at,'
            . 'a.command_name,a.status command_status,a.source,a.duration_ms,a.started_at,'
            . 'o.event_type,o.status event_status,o.processed_at';
        $sql = "SELECT {$columns} FROM `{$touches}` t "
            . "LEFT JOIN `{$audit}` a ON a.command_id=t.command_id AND a.blog_id=t.blog_id "
            . "LEFT JOIN `{$outbox}` o ON o.event_id=t.event_id AND o.blog_id=t.blog_id "
            . 'WHERE t.aggregate=%s AND t.aggregate_id=%s '
            . 'ORDER BY t.version ASC,t.occurred_at ASC,t.id ASC';
        $entries = $this->db->results($this->db->prepare($sql, [$aggregate, $aggregateId]));
        foreach ($entries as &$entry) {
            $entry['id'] = (int) ($entry['id'] ?? 0);
            $entry['version'] = (int) ($entry['version'] ?? 0);
            $entry['duration_ms'] = isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : null;
        }
        unset($entry);

        $versions = array_column($entries, 'version');
        return [
            'available' => true,
            'aggregate' => $aggregate,
            'aggregate_id' => $aggregateId,
            'summary' => [
                'touch_count' => count($entries),
                'first_version' => $versions !== [] ? min($versions) : null,
                'last_version' => $versions !== [] ? max($versions) : null,
                'first_at' => $entries[0]['occurred_at'] ?? null,
                'last_at' => $entries !== [] ? $entries[array_key_last($entries)]['occurred_at'] : null,
            ],
            'entries' => $entries,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyRecent(int $page, int $perPage): array
    {
        return [
            'available' => false,
            'rows' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyBiography(string $aggregate, string $aggregateId): array
    {
        return [
            'available' => false,
            'aggregate' => $aggregate,
            'aggregate_id' => $aggregateId,
            'summary' => [
                'touch_count' => 0,
                'first_version' => null,
                'last_version' => null,
                'first_at' => null,
                'last_at' => null,
            ],
            'entries' => [],
        ];
    }
}
