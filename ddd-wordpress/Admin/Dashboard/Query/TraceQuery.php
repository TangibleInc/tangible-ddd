<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class TraceQuery
{
    public function __construct(
        private readonly IDDDConfig $config,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function assemble(string $correlationId): array
    {
        $audit = $this->config->table('command_audit');
        $outbox = $this->config->table('integration_outbox');
        $processes = $this->config->table('long_processes');
        $workflowTable = $this->config->table('behaviour_workflows');

        $commands = $this->db->results($this->db->prepare(
            "SELECT command_id,correlation_id,command_name,status,source,source_id,causation_id,causation_type,
                    duration_ms,peak_memory_bytes,started_at,parameters,events,error
             FROM `{$audit}` WHERE correlation_id=%s ORDER BY started_at ASC",
            [$correlationId],
        ));
        $events = $this->db->results($this->db->prepare(
            "SELECT event_id,event_type,status,command_id,sequence,attempts,created_at
             FROM `{$outbox}` WHERE correlation_id=%s ORDER BY sequence ASC, created_at ASC",
            [$correlationId],
        ));
        $processRows = $this->db->results($this->db->prepare(
            "SELECT id,process_class,status,step_name,waiting_for,created_at,updated_at
             FROM `{$processes}` WHERE correlation_id=%s",
            [$correlationId],
        ));
        $workflowRows = $this->db->results($this->db->prepare(
            "SELECT id,ref_id,ref_type,root_workflow_id,behaviour_configs,behaviour_results,
                    current_idx,current_phase,is_complete,is_failed,created_at
             FROM `{$workflowTable}` WHERE correlation_id=%s ORDER BY id ASC",
            [$correlationId],
        ));

        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[$event['event_id']] = $event;
        }
        $commandsById = [];
        foreach ($commands as $command) {
            $commandsById[$command['command_id']] = $command;
        }
        $processesById = [];
        foreach ($processRows as $process) {
            $processesById[(string) $process['id']] = $process;
        }

        $epoch = static fn (mixed $value): int => $value ? (int) strtotime((string) $value . ' UTC') : 0;
        $minTimestamp = PHP_INT_MAX;
        $maxTimestamp = 0;
        $nodes = [];
        $children = [];
        $roots = [];

        foreach ($commands as $command) {
            $uid = 'c:' . $command['command_id'];
            $started = $epoch($command['started_at']);
            $duration = (int) $command['duration_ms'];
            $minTimestamp = min($minTimestamp, $started);
            $maxTimestamp = max($maxTimestamp, $started + (int) ceil($duration / 1000));
            $nodes[$uid] = $this->commandNode($command, $uid, $started, $duration);
        }
        foreach ($events as $event) {
            $uid = 'e:' . $event['event_id'];
            $started = $epoch($event['created_at']);
            $minTimestamp = min($minTimestamp, $started);
            $maxTimestamp = max($maxTimestamp, $started);
            $parent = ($event['command_id'] && isset($commandsById[$event['command_id']]))
                ? 'c:' . $event['command_id']
                : null;
            $nodes[$uid] = $this->eventNode(
                $event,
                $uid,
                $started,
                $parent,
                $parent ? $commandsById[$event['command_id']]['command_name'] : null,
            );
        }
        foreach ($processRows as $process) {
            $uid = 'p:' . $process['id'];
            $started = $epoch($process['created_at']);
            $minTimestamp = min($minTimestamp, $started);
            $maxTimestamp = max($maxTimestamp, $epoch($process['updated_at']) ?: $started);
            $nodes[$uid] = $this->processNode($process, $uid, $started);
        }

        foreach ($commands as $command) {
            $uid = 'c:' . $command['command_id'];
            if (
                $command['causation_type'] === 'integration_event'
                && $command['causation_id']
                && isset($eventsById[$command['causation_id']])
            ) {
                $nodes[$uid]['parent'] = 'e:' . $command['causation_id'];
                $nodes[$uid]['parent_label'] = $eventsById[$command['causation_id']]['event_type'];
            } elseif (
                $command['causation_type'] === 'long_process'
                && $command['causation_id']
                && isset($processesById[(string) $command['causation_id']])
            ) {
                $nodes[$uid]['parent'] = 'p:' . $command['causation_id'];
                $nodes[$uid]['parent_label'] = $processesById[(string) $command['causation_id']]['process_class'];
            }
        }
        foreach ($nodes as $uid => $node) {
            if ($node['parent'] && isset($nodes[$node['parent']])) {
                $children[$node['parent']][] = $uid;
            } else {
                $roots[] = $uid;
            }
        }
        if ($minTimestamp === PHP_INT_MAX) {
            $minTimestamp = 0;
        }

        $byTimestamp = static fn (string $left, string $right): int => $nodes[$left]['ts'] <=> $nodes[$right]['ts'];
        usort($roots, $byTimestamp);
        $ordered = [];
        $walk = function (string $uid, int $depth, int $parentEnd, ?int $parentTimestamp) use (
            &$walk,
            &$ordered,
            $children,
            $nodes,
            $byTimestamp,
        ): void {
            $node = $nodes[$uid];
            $wallGap = $parentTimestamp !== null ? max(0, $node['ts'] - $parentTimestamp) : 0;
            $gapBefore = null;
            if ($parentTimestamp === null) {
                $compressedStart = 0;
            } elseif ($wallGap >= 2) {
                $compressedStart = $parentEnd + 130;
                $gapBefore = $wallGap;
            } else {
                $compressedStart = $parentEnd + 10;
            }
            $compressedWidth = max($node['dur_ms'], 16);
            $node['cstart'] = $compressedStart;
            $node['cend'] = $compressedStart + $compressedWidth;
            $node['gap_before'] = $gapBefore;
            $node['depth'] = $depth;
            $ordered[] = $node;

            $descendants = $children[$uid] ?? [];
            usort($descendants, $byTimestamp);
            foreach ($descendants as $child) {
                $childDepth = $nodes[$child]['kind'] === 'event' ? $depth : $depth + 1;
                $walk($child, $childDepth, $node['cend'], $node['ts']);
            }
        };
        foreach ($roots as $root) {
            $walk($root, 0, 0, null);
        }

        $totalUnits = 1;
        foreach ($ordered as $node) {
            $totalUnits = max($totalUnits, $node['cend']);
        }
        $totalUnits += 110;
        $outputNodes = array_map(static function (array $node) use ($totalUnits): array {
            $node['start_pct'] = round($node['cstart'] / $totalUnits * 100, 2);
            $node['width_pct'] = round(max(($node['cend'] - $node['cstart']) / $totalUnits * 100, 0.6), 2);
            unset($node['ts'], $node['cstart'], $node['cend']);
            return $node;
        }, $ordered);

        $hasError = false;
        $maxDuration = 0;
        foreach ($commands as $command) {
            $hasError = $hasError || $command['status'] === 'error';
            $maxDuration = max($maxDuration, (int) $command['duration_ms']);
        }

        $workflows = array_map(static function (array $workflow): array {
            foreach (['behaviour_configs', 'behaviour_results'] as $column) {
                $value = $workflow[$column] ?? null;
                $workflow[$column] = ($value !== null && $value !== '') ? json_decode((string) $value, true) : null;
            }
            foreach (['id', 'ref_id', 'current_idx', 'current_phase', 'is_complete', 'is_failed'] as $column) {
                $workflow[$column] = (int) $workflow[$column];
            }
            $root = $workflow['root_workflow_id'];
            $workflow['root_workflow_id'] = ($root !== null && $root !== '') ? (int) $root : null;
            return $workflow;
        }, $workflowRows);

        return [
            'correlation_id' => $correlationId,
            'span_count' => count($commands),
            'event_count' => count($events),
            'process_count' => count($processRows),
            'workflow_count' => count($workflows),
            'total_ms' => max(($maxTimestamp - $minTimestamp) * 1000, $maxDuration),
            'max_dur_ms' => $maxDuration,
            'started_at' => $commands[0]['started_at'] ?? ($events[0]['created_at'] ?? null),
            'has_error' => $hasError,
            'nodes' => $outputNodes,
            'workflows' => $workflows,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function commandNode(array $command, string $uid, int $started, int $duration): array
    {
        return [
            'uid' => $uid,
            'kind' => 'command',
            'id' => $command['command_id'],
            'name' => $command['command_name'],
            'is_workflow' => (bool) preg_match('/Behaviour|Workflow/', (string) $command['command_name']),
            'status' => $command['status'],
            'source' => $command['source'] . ($command['source_id'] ? '#' . $command['source_id'] : ''),
            'ts' => $started,
            'dur_ms' => $duration,
            'raw' => $this->decodeCommand($command),
            'parent' => null,
            'parent_label' => null,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function eventNode(
        array $event,
        string $uid,
        int $started,
        ?string $parent,
        mixed $parentLabel,
    ): array {
        return [
            'uid' => $uid,
            'kind' => 'event',
            'id' => $event['event_id'],
            'name' => $event['event_type'],
            'status' => $event['status'],
            'source' => null,
            'ts' => $started,
            'dur_ms' => 0,
            'raw' => [
                'event_id' => $event['event_id'],
                'event_type' => $event['event_type'],
                'status' => $event['status'],
                'attempts' => (int) $event['attempts'],
                'sequence' => (int) $event['sequence'],
            ],
            'parent' => $parent,
            'parent_label' => $parentLabel,
        ];
    }

    /**
     * @param array<string, mixed> $process
     * @return array<string, mixed>
     */
    private function processNode(array $process, string $uid, int $started): array
    {
        return [
            'uid' => $uid,
            'kind' => 'process',
            'id' => (int) $process['id'],
            'name' => $process['process_class'],
            'status' => $process['status'],
            'source' => $process['waiting_for'] ? 'waiting: ' . $process['waiting_for'] : null,
            'ts' => $started,
            'dur_ms' => 0,
            'raw' => $process,
            'parent' => null,
            'parent_label' => null,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function decodeCommand(array $command): array
    {
        foreach (['parameters', 'events', 'error'] as $column) {
            $value = $command[$column] ?? null;
            $command[$column] = ($value !== null && $value !== '') ? json_decode((string) $value, true) : null;
        }
        $command['duration_ms'] = (int) $command['duration_ms'];
        return $command;
    }
}
