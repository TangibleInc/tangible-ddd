<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class ProcessQuery
{
    private const STATUSES = ['pending', 'running', 'scheduled', 'suspended', 'completed', 'failed'];

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
        $table = $this->config->table('long_processes');
        $where = ['1=1'];
        $params = [];
        if (! empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status=%s';
            $params[] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->value($this->db->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}",
            $params,
        ));
        $sql = "SELECT id,process_class,status,step_index,step_name,waiting_for,match_criteria,business_data,steps,
                       correlation_id,last_error,created_at,updated_at
                FROM `{$table}` WHERE {$whereSql} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
        $rows = $this->db->results($this->db->prepare($sql, [...$params, $perPage, $offset]));
        foreach ($rows as &$row) {
            foreach (['steps', 'business_data', 'match_criteria'] as $column) {
                $value = $row[$column] ?? null;
                $row[$column] = ($value !== null && $value !== '') ? json_decode((string) $value, true) : null;
            }
            $row['id'] = (int) $row['id'];
            $row['step_index'] = (int) $row['step_index'];
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
