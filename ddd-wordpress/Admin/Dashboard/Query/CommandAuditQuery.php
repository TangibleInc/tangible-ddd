<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class CommandAuditQuery
{
    private const SORTABLE = ['started_at', 'duration_ms', 'command_name', 'status'];
    private const STATUSES = ['success', 'error', 'in_progress'];
    private const SOURCES = ['user', 'cli', 'system', 'action_scheduler'];

    public function __construct(
        private readonly IDDDConfig $config,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function run(array $filters): array
    {
        $table = $this->config->table('command_audit');
        $where = ['1=1'];
        $params = [];

        if (! empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (! empty($filters['source']) && in_array($filters['source'], self::SOURCES, true)) {
            $where[] = 'source = %s';
            $params[] = $filters['source'];
        }
        if (! empty($filters['search'])) {
            $like = '%' . $this->db->escapeLike((string) $filters['search']) . '%';
            $where[] = '(command_name LIKE %s OR command_id LIKE %s OR correlation_id LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if (! empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $filters['from'])) {
            $timestamp = strtotime((string) $filters['from']);
            if ($timestamp !== false) {
                $where[] = 'started_at >= %s';
                $params[] = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }
        if (! empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $filters['to'])) {
            $timestamp = strtotime((string) $filters['to'] . ' 23:59:59');
            if ($timestamp !== false) {
                $where[] = 'started_at <= %s';
                $params[] = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }
        $whereSql = implode(' AND ', $where);

        $orderby = in_array($filters['orderby'] ?? '', self::SORTABLE, true)
            ? $filters['orderby']
            : 'started_at';
        $order = strtoupper((string) ($filters['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}";
        $total = (int) $this->db->value($this->db->prepare($countSql, $params));

        $columns = 'command_id, correlation_id, command_name, status, source, source_id, '
            . 'causation_id, causation_type, duration_ms, peak_memory_bytes, started_at, ended_at, '
            . 'parameters, events, error';
        $pageSql = "SELECT {$columns} FROM `{$table}` WHERE {$whereSql} "
            . "ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $rows = $this->db->results($this->db->prepare($pageSql, [...$params, $perPage, $offset]));

        foreach ($rows as &$row) {
            foreach (['parameters', 'events', 'error'] as $jsonColumn) {
                $value = $row[$jsonColumn] ?? null;
                $row[$jsonColumn] = ($value !== null && $value !== '') ? json_decode((string) $value, true) : null;
            }
            $row['duration_ms'] = (int) $row['duration_ms'];
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
        ];
    }
}
