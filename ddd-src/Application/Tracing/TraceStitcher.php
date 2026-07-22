<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Tracing;

/**
 * Joins consumer-owned trace fragments without knowing how they were stored.
 *
 * Input rows intentionally retain their at-rest vocabulary. This class is the
 * projection boundary that turns those rows into one collision-safe graph.
 */
final class TraceStitcher
{
    /**
     * @param list<array<string, mixed>> $fragments
     * @return array{
     *     nodes: array<string, array<string, mixed>>,
     *     workflows: list<array<string, mixed>>,
     *     participants: array<string, array<string, mixed>>,
     *     warnings: list<array<string, mixed>>
     * }
     */
    public function stitch(array $fragments): array
    {
        $nodes = [];
        $workflows = [];
        $participants = [];
        $warnings = [];
        $commands = [];
        $events = [];
        $processes = [];

        foreach ($fragments as $fragment) {
            $consumer = $this->consumer($fragment);
            $key = $consumer['key'];
            $hasEvidence = false;
            foreach (($fragment['warnings'] ?? []) as $warning) {
                $warning['consumer'] ??= $key;
                $warnings[] = $warning;
            }

            foreach (($fragment['commands'] ?? []) as $command) {
                $hasEvidence = true;
                $uid = $this->uid($key, 'c', (string) $command['command_id']);
                $nodes[$uid] = $this->commandNode($command, $uid, $consumer);
                $commands[$key][(string) $command['command_id']] = $uid;
                $commands['*'][(string) $command['command_id']][] = $uid;
            }

            foreach (($fragment['events'] ?? []) as $event) {
                $hasEvidence = true;
                $uid = $this->uid($key, 'e', (string) $event['event_id']);
                $nodes[$uid] = $this->eventNode($event, $uid, $consumer);
                $events[(string) $event['event_id']][] = $uid;
            }

            foreach (($fragment['processes'] ?? []) as $process) {
                $hasEvidence = true;
                $id = (string) $process['id'];
                $uid = $this->uid($key, 'p', $id);
                $nodes[$uid] = $this->processNode($process, $uid, $consumer);
                $processes[$key][$id] = $uid;
                $processes['*'][$id][] = $uid;
            }

            foreach (($fragment['workflows'] ?? []) as $workflow) {
                $hasEvidence = true;
                $workflow['consumer'] = $key;
                $workflow['consumer_label'] = $consumer['label'];
                $workflow['accent'] = $consumer['accent'];
                $workflow['uid'] = $this->uid($key, 'w', (string) $workflow['id']);
                $workflows[] = $workflow;
            }

            foreach (($fragment['touches'] ?? []) as $touch) {
                $eventId = $this->nullableString($touch['event_id'] ?? null);
                $commandId = $this->nullableString($touch['command_id'] ?? null);
                $target = $eventId !== null ? $this->uid($key, 'e', $eventId) : null;
                if ($target === null || ! isset($nodes[$target])) {
                    $target = $commandId !== null ? ($commands[$key][$commandId] ?? null) : null;
                }
                if ($target === null || ! isset($nodes[$target])) {
                    continue;
                }
                $touch['id'] = (int) ($touch['id'] ?? 0);
                $touch['version'] = (int) ($touch['version'] ?? 0);
                $touch['consumer'] = $key;
                $touch['consumer_label'] = $consumer['label'];
                $touch['accent'] = $consumer['accent'];
                $nodes[$target]['touches'][] = $touch;
            }

            if ($hasEvidence) {
                $participants[$key] = $consumer;
            }
        }

        $recordedNodeUids = array_keys($nodes);
        foreach ($recordedNodeUids as $uid) {
            $node = &$nodes[$uid];
            $parent = null;
            $causeType = null;
            $causeId = null;
            $candidates = [];

            if ($node['kind'] === 'event' && $node['raised_by'] !== null) {
                $causeType = 'command';
                $causeId = $node['raised_by'];
                $local = $commands[$node['consumer']][$causeId] ?? null;
                $candidates = $local !== null ? [$local] : [];
            } elseif ($node['kind'] === 'process' && $node['ignited_by'] !== null) {
                $causeType = 'integration_event';
                $causeId = $node['ignited_by'];
                $candidates = $events[$causeId] ?? [];
            } elseif ($node['kind'] === 'command' && $node['causation_id'] !== null) {
                $causeType = $node['causation_type'];
                $causeId = $node['causation_id'];
                if ($causeType === 'long_process') {
                    $local = $processes[$node['consumer']][$causeId] ?? null;
                    $candidates = $local !== null ? [$local] : ($processes['*'][$causeId] ?? []);
                } else {
                    $candidates = match ($causeType) {
                        'integration_event' => $events[$causeId] ?? [],
                        'command' => $commands['*'][$causeId] ?? [],
                        default => [],
                    };
                }
            }

            if ($causeId === null) {
                continue;
            }

            $parent = count($candidates) === 1 ? $candidates[0] : null;
            if ($parent === null) {
                $ambiguous = count($candidates) > 1;
                $parent = $ambiguous
                    ? $this->ambiguousUid((string) $causeType, $causeId)
                    : $this->missingUid($node['consumer'], (string) $causeType, $causeId);
                if (! isset($nodes[$parent])) {
                    $nodes[$parent] = $this->unresolvedNode(
                        $parent,
                        (string) $causeType,
                        $causeId,
                        $node,
                        $ambiguous,
                        $candidates,
                    );
                }
                $warnings[] = [
                    'code' => $ambiguous ? 'ambiguous_parent' : 'unresolved_parent',
                    'message' => $ambiguous
                        ? 'Recorded parent matches multiple consumer-owned rows'
                        : $this->unresolvedMessage((string) $causeType),
                    'consumer' => $node['consumer'],
                    'node_uid' => $uid,
                    'causation_type' => $causeType,
                    'causation_id' => $causeId,
                    'candidates' => $candidates,
                ];
            }

            $node['parent'] = $parent;
            $node['parent_label'] = $nodes[$parent]['name'];
            $node['parent_consumer'] = $nodes[$parent]['consumer'];
            $node['parent_consumer_label'] = $nodes[$parent]['consumer_label'];
            $node['parent_accent'] = $nodes[$parent]['accent'];
            $node['cross_consumer'] = $nodes[$parent]['consumer'] !== $node['consumer']
                && ! in_array($nodes[$parent]['consumer'], ['missing', 'ambiguous'], true);
        }
        unset($node);

        return [
            'nodes' => $nodes,
            'workflows' => $workflows,
            'participants' => $participants,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string, mixed> $fragment @return array{key: string, label: string, accent: string, ghost: bool} */
    private function consumer(array $fragment): array
    {
        $consumer = $fragment['consumer'] ?? [];
        $key = (string) ($consumer['key'] ?? 'unknown');

        return [
            'key' => $key,
            'label' => (string) ($consumer['label'] ?? $key),
            'accent' => (string) ($consumer['accent'] ?? '#646970'),
            'ghost' => (bool) ($consumer['ghost'] ?? false),
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $consumer @return array<string, mixed> */
    private function commandNode(array $row, string $uid, array $consumer): array
    {
        $duration = (int) ($row['duration_ms'] ?? 0);

        return $this->node($uid, 'command', (string) $row['command_id'], (string) $row['command_name'], $consumer) + [
            'status' => (string) ($row['status'] ?? ''),
            'source' => (string) ($row['source'] ?? '') . (($row['source_id'] ?? null) ? '#' . $row['source_id'] : ''),
            'ts' => $this->epoch($row['started_at'] ?? null),
            'dur_ms' => $duration,
            'raw' => $this->decodeCommand($row),
            'is_workflow' => (bool) preg_match('/Behaviour|Workflow/', (string) $row['command_name']),
            'causation_id' => $this->nullableString($row['causation_id'] ?? null),
            'causation_type' => $this->nullableString($row['causation_type'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $consumer @return array<string, mixed> */
    private function eventNode(array $row, string $uid, array $consumer): array
    {
        return $this->node($uid, 'event', (string) $row['event_id'], (string) $row['event_type'], $consumer) + [
            'status' => (string) ($row['status'] ?? ''),
            'source' => null,
            'ts' => $this->epoch($row['created_at'] ?? null),
            'dur_ms' => 0,
            'raw' => [
                'event_id' => $row['event_id'],
                'event_type' => $row['event_type'],
                'status' => $row['status'] ?? null,
                'attempts' => (int) ($row['attempts'] ?? 0),
                'sequence' => (int) ($row['sequence'] ?? 0),
                'command_id' => $row['command_id'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ],
            'raised_by' => $this->nullableString($row['command_id'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $consumer @return array<string, mixed> */
    private function processNode(array $row, string $uid, array $consumer): array
    {
        return $this->node($uid, 'process', (string) $row['id'], (string) $row['process_class'], $consumer) + [
            'status' => (string) ($row['status'] ?? ''),
            'source' => ($row['waiting_for'] ?? null) ? 'waiting: ' . $row['waiting_for'] : null,
            'ts' => $this->epoch($row['created_at'] ?? null),
            'dur_ms' => 0,
            'raw' => $row,
            'ignited_by' => $this->nullableString($row['ignited_by_event_id'] ?? null),
        ];
    }

    /** @param array<string, mixed> $consumer @return array<string, mixed> */
    private function node(string $uid, string $kind, string $id, string $name, array $consumer): array
    {
        return [
            'uid' => $uid,
            'kind' => $kind,
            'id' => $id,
            'name' => $name,
            'consumer' => $consumer['key'],
            'consumer_label' => $consumer['label'],
            'accent' => $consumer['accent'],
            'ghost' => $consumer['ghost'],
            'parent' => null,
            'parent_label' => null,
            'parent_consumer' => null,
            'parent_consumer_label' => null,
            'parent_accent' => null,
            'cross_consumer' => false,
            'unresolved' => false,
        ];
    }

    /** @param array<string, mixed> $child @param list<string> $candidates @return array<string, mixed> */
    private function unresolvedNode(
        string $uid,
        string $type,
        string $id,
        array $child,
        bool $ambiguous,
        array $candidates,
    ): array
    {
        $kind = match ($type) {
            'integration_event' => 'event',
            'long_process' => 'process',
            'command' => 'command',
            default => 'unknown',
        };

        return [
            'uid' => $uid,
            'kind' => $kind,
            'id' => $id,
            'name' => ($ambiguous ? 'Ambiguous ' : 'Missing ') . str_replace('_', ' ', $type),
            'consumer' => $ambiguous ? 'ambiguous' : 'missing',
            'consumer_label' => $ambiguous ? 'Ambiguous' : 'Unresolved',
            'accent' => '#646970',
            'ghost' => true,
            'status' => 'missing',
            'source' => null,
            'ts' => $child['ts'],
            'dur_ms' => 0,
            'raw' => [
                'causation_type' => $type,
                'causation_id' => $id,
                'candidates' => $candidates,
            ],
            'parent' => null,
            'parent_label' => null,
            'parent_consumer' => null,
            'parent_consumer_label' => null,
            'parent_accent' => null,
            'cross_consumer' => false,
            'unresolved' => true,
        ];
    }

    private function missingUid(string $consumer, string $type, string $id): string
    {
        return $type === 'integration_event'
            ? 'missing:e:' . $id
            : $consumer . ':missing:' . match ($type) {
                'long_process' => 'p',
                'command' => 'c',
                default => 'u',
            } . ':' . $id;
    }

    private function ambiguousUid(string $type, string $id): string
    {
        return 'ambiguous:' . match ($type) {
            'integration_event' => 'e',
            'long_process' => 'p',
            'command' => 'c',
            default => 'u',
        } . ':' . $id;
    }

    private function unresolvedMessage(string $type): string
    {
        return match ($type) {
            'integration_event' => 'Recorded integration event was not found in any consumer',
            'long_process' => 'Recorded long process was not found in its consumer',
            'command' => 'Recorded command was not found in any consumer',
            default => 'Recorded parent has an unknown causation type',
        };
    }

    private function uid(string $consumer, string $kind, string $id): string
    {
        return $consumer . ':' . $kind . ':' . $id;
    }

    private function epoch(mixed $value): int
    {
        return $value ? (int) strtotime((string) $value . ' UTC') : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decodeCommand(array $row): array
    {
        foreach (['parameters', 'events', 'error'] as $column) {
            $value = $row[$column] ?? null;
            $row[$column] = $value !== null && $value !== '' ? json_decode((string) $value, true) : null;
        }
        $row['duration_ms'] = (int) ($row['duration_ms'] ?? 0);
        return $row;
    }
}
