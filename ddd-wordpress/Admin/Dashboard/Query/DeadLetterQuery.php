<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class DeadLetterQuery
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
    public function list(array $filters): array
    {
        $table = $this->config->table('integration_dlq');
        $where = ['1=1'];
        $params = [];
        if (! empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $filters['from'])) {
            $timestamp = strtotime((string) $filters['from']);
            if ($timestamp !== false) {
                $where[] = 'moved_at >= %s';
                $params[] = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }
        if (! empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $filters['to'])) {
            $timestamp = strtotime((string) $filters['to'] . ' 23:59:59');
            if ($timestamp !== false) {
                $where[] = 'moved_at <= %s';
                $params[] = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }
        $whereSql = implode(' AND ', $where);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->value($this->db->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}",
            $params,
        ));
        $rows = $this->db->results($this->db->prepare(
            "SELECT id,event_type,correlation_id,command_id,attempts,final_error,moved_at
             FROM `{$table}` WHERE {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d",
            [...$params, $perPage, $offset],
        ));
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['attempts'] = (int) $row['attempts'];
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
