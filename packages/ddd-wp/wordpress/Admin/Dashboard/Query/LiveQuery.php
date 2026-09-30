<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class LiveQuery
{
    public function __construct(
        private readonly IDDDConfig $config,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function tick(int $sinceId): array
    {
        $audit = $this->config->table('command_audit');
        $columns = 'id,command_id,correlation_id,command_name,status,source,source_id,duration_ms,started_at';
        $rows = $sinceId <= 0
            ? $this->db->results("SELECT {$columns} FROM `{$audit}` ORDER BY id DESC LIMIT 25")
            : $this->db->results($this->db->prepare(
                "SELECT {$columns} FROM `{$audit}` WHERE id>%d ORDER BY id DESC LIMIT 50",
                [$sinceId],
            ));

        $cursor = $sinceId;
        foreach ($rows as &$row) {
            $cursor = max($cursor, (int) $row['id']);
            $row['id'] = (int) $row['id'];
            $row['duration_ms'] = (int) $row['duration_ms'];
        }
        unset($row);

        return [
            'rows' => $rows,
            'cursor' => $cursor,
            'counts' => [
                'dlq' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM `{$this->config->table('integration_dlq')}`",
                ),
                'outbox' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM `{$this->config->table('integration_outbox')}` "
                    . "WHERE status IN ('pending','processing')",
                ),
            ],
        ];
    }
}
