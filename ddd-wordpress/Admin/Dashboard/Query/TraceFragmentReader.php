<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\WordPress\Admin\Dashboard\ConsumerDefinition;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class TraceFragmentReader
{
    public function __construct(
        private readonly ConsumerDefinition $consumer,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function read(string $correlationId): array
    {
        $fragment = [
            'consumer' => [
                'key' => $this->consumer->key,
                'label' => $this->consumer->label,
                'accent' => $this->consumer->accent,
                'ghost' => $this->consumer->ghost,
            ],
            'commands' => [],
            'events' => [],
            'processes' => [],
            'workflows' => [],
            'touches' => [],
            'warnings' => [],
        ];
        $config = $this->consumer->config();
        if ($config === null) {
            $fragment['warnings'][] = [
                'code' => 'consumer_unavailable',
                'message' => 'Consumer configuration is not available',
            ];
            return $fragment;
        }

        $queries = [
            'commands' => [
                'table' => 'command_audit',
                'sql' => 'SELECT command_id,correlation_id,command_name,status,source,source_id,causation_id,'
                    . 'causation_type,duration_ms,peak_memory_bytes,started_at,ended_at,parameters,events,error '
                    . 'FROM `%s` WHERE correlation_id=%%s ORDER BY started_at ASC',
            ],
            'events' => [
                'table' => 'integration_outbox',
                'sql' => 'SELECT event_id,event_type,status,command_id,sequence,attempts,created_at '
                    . 'FROM `%s` WHERE correlation_id=%%s ORDER BY sequence ASC, created_at ASC',
            ],
            'processes' => [
                'table' => 'long_processes',
                'sql' => 'SELECT id,process_class,status,step_name,waiting_for,ignited_by_event_id,created_at,updated_at '
                    . 'FROM `%s` WHERE correlation_id=%%s',
            ],
            'workflows' => [
                'table' => 'behaviour_workflows',
                'sql' => 'SELECT id,ref_id,ref_type,root_workflow_id,behaviour_configs,behaviour_results,'
                    . 'current_idx,current_phase,is_complete,is_failed,created_at '
                    . 'FROM `%s` WHERE correlation_id=%%s ORDER BY id ASC',
            ],
            'touches' => [
                'table' => 'touches',
                'sql' => 'SELECT id,aggregate,aggregate_id,op,version,event_name,event_id,command_id,'
                    . 'correlation_id,occurred_at FROM `%s` WHERE correlation_id=%%s '
                    . 'ORDER BY occurred_at ASC,version ASC,id ASC',
            ],
        ];

        foreach ($queries as $target => $query) {
            $table = $config->table($query['table']);
            if (! $this->db->tableExists($table)) {
                $fragment['warnings'][] = [
                    'code' => 'missing_table',
                    'message' => "Consumer table '{$table}' is not available",
                    'table' => $query['table'],
                ];
                continue;
            }
            $sql = sprintf($query['sql'], $table);
            $fragment[$target] = $this->db->results($this->db->prepare($sql, [$correlationId]));
        }

        return $fragment;
    }
}
