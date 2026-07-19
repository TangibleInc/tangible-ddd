<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class WorkflowQuery
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
        $workflows = $this->config->table('behaviour_workflows');
        $items = $this->config->table('behaviour_workflow_items');
        $where = ['1=1'];
        $state = $filters['state'] ?? '';
        if ($state === 'failed') {
            $where[] = 'is_failed=1';
        } elseif ($state === 'running') {
            $where[] = 'is_complete=0 AND is_failed=0';
        } elseif ($state === 'complete') {
            $where[] = 'is_complete=1';
        }
        $whereSql = implode(' AND ', $where);
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->value("SELECT COUNT(*) FROM `{$workflows}` WHERE {$whereSql}");
        $available = $this->db->column("SHOW COLUMNS FROM `{$workflows}`");
        $wanted = [
            'id', 'ref_id', 'ref_type', 'root_workflow_id', 'behaviour_configs', 'behaviour_results',
            'current_idx', 'current_phase', 'is_complete', 'is_failed', 'meta', 'created_at', 'updated_at',
        ];
        $columns = array_values(array_intersect($wanted, $available));
        $columnSql = implode(',', array_map(static fn (string $column): string => "`{$column}`", $columns));
        $order = in_array('updated_at', $available, true)
            ? 'updated_at'
            : (in_array('created_at', $available, true) ? 'created_at' : 'id');
        $rows = $this->db->results($this->db->prepare(
            "SELECT {$columnSql} FROM `{$workflows}` WHERE {$whereSql} "
            . "ORDER BY {$order} DESC LIMIT %d OFFSET %d",
            [$perPage, $offset],
        ));

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $itemsByWorkflow = [];
        $forksByWorkflow = [];
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $itemRows = $this->db->results($this->db->prepare(
                "SELECT workflow_id,behaviour_idx,phase,item_key,status,attempts FROM `{$items}` "
                . "WHERE workflow_id IN ({$placeholders}) ORDER BY behaviour_idx,phase,id",
                $ids,
            ));
            foreach ($itemRows as $item) {
                $itemsByWorkflow[(int) $item['workflow_id']][] = [
                    'behaviour_idx' => (int) $item['behaviour_idx'],
                    'phase' => (int) $item['phase'],
                    'item_key' => $item['item_key'],
                    'status' => $item['status'],
                    'attempts' => (int) $item['attempts'],
                ];
            }
            $forkRows = $this->db->results($this->db->prepare(
                "SELECT id,root_workflow_id,is_complete,is_failed,current_idx FROM `{$workflows}` "
                . "WHERE root_workflow_id IN ({$placeholders})",
                $ids,
            ));
            foreach ($forkRows as $fork) {
                $forksByWorkflow[(int) $fork['root_workflow_id']][] = [
                    'id' => (int) $fork['id'],
                    'is_complete' => (int) $fork['is_complete'],
                    'is_failed' => (int) $fork['is_failed'],
                    'current_idx' => (int) $fork['current_idx'],
                ];
            }
        }

        foreach ($rows as &$row) {
            foreach (['behaviour_configs', 'behaviour_results', 'meta'] as $column) {
                $value = $row[$column] ?? null;
                $row[$column] = ($value !== null && $value !== '') ? json_decode((string) $value, true) : null;
            }
            foreach (['id', 'ref_id', 'current_idx', 'current_phase', 'is_complete', 'is_failed'] as $column) {
                $row[$column] = (int) $row[$column];
            }
            $row['root_workflow_id'] = $row['root_workflow_id'] !== null
                ? (int) $row['root_workflow_id']
                : null;
            $row['items'] = $itemsByWorkflow[$row['id']] ?? [];
            $row['forks'] = $forksByWorkflow[$row['id']] ?? [];
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
