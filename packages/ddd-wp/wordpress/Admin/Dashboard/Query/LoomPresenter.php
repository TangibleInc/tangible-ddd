<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

/**
 * The Loom: a workflow's progress as SEGMENTS (each behaviour with its own
 * item cells) and BRACKETS (each pass window over the cells it executed).
 *
 * Sources — all pre-existing data:
 *  - behaviour_results: every step-execution persisted its processed item
 *    keys (batch_success / batch_error) with an ISO timestamp; re-executions
 *    chain into history[]. This IS the pass ledger.
 *  - pass windows: the workflow-driving commands' command_audit rows
 *    (started_at + duration_ms), ordered = pass ordinals.
 *  - behaviour_workflow_items: cell states (status, attempts).
 *  - config batches: ghost cells for unreached behaviours.
 *
 * Binding is floor-by-timestamp: an execution entry belongs to the latest
 * pass window that started at-or-before it (entries are written during the
 * pass; second-granularity is fine at real budgets). Entries with no window
 * become "unbound" brackets — evidence preserved, never invented.
 */
final class LoomPresenter
{
    /**
     * @param array<string, mixed> $workflow decoded behaviour_configs/behaviour_results + items rows
     * @param list<array{command_id: string, ts: int, dur_ms: int}> $windows pass commands, any order
     * @return array{segments: list<array<string, mixed>>, brackets: list<array<string, mixed>>}
     */
    public function present(array $workflow, array $windows): array
    {
        $segments = $this->segments($workflow);
        $cellIndex = $this->cell_index($segments);

        usort($windows, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $entries = $this->execution_entries($workflow['behaviour_results'] ?? []);
        $groups = [];
        foreach ($entries as $entry) {
            $ordinal = $this->bind($entry['ts'], $windows);
            $key = $ordinal !== null ? "p{$ordinal}" : 'u' . count($groups);
            $groups[$key]['pass'] = $ordinal;
            $groups[$key]['command_id'] = $ordinal !== null ? $windows[$ordinal - 1]['command_id'] : null;
            $groups[$key]['entries'][] = $entry;
        }

        $brackets = [];
        foreach ($groups as $group) {
            $keys = [];
            $errors = 0;
            $cut = false;
            foreach ($group['entries'] as $entry) {
                foreach ($entry['keys'] as $itemKey) {
                    $keys[] = [$entry['behaviour_idx'], $itemKey];
                }
                $errors += $entry['errors'];
                $cut = $cut || $entry['status'] === 'batched';
            }

            $cells = [];
            foreach ($keys as [$idx, $itemKey]) {
                if (isset($cellIndex[$idx][$itemKey])) {
                    $cells[] = ['seg' => $idx, 'cell' => $cellIndex[$idx][$itemKey]];
                }
            }
            usort($cells, static fn (array $a, array $b): int => [$a['seg'], $a['cell']] <=> [$b['seg'], $b['cell']]);

            $spans = [];
            foreach ($cells as $cell) {
                $spans[$cell['seg']] = ($spans[$cell['seg']] ?? 0) + 1;
            }

            $brackets[] = [
                'pass' => $group['pass'],
                'command_id' => $group['command_id'],
                'from' => $cells[0] ?? null,
                'to' => $cells !== [] ? $cells[count($cells) - 1] : null,
                'keys' => array_map(static fn (array $pair): string => $pair[1], $keys),
                'spans' => array_map(
                    static fn (int $seg): array => ['seg' => $seg, 'count' => $spans[$seg]],
                    array_keys($spans),
                ),
                'errors' => $errors,
                'cut' => $cut,
            ];
        }

        usort($brackets, static fn (array $a, array $b): int => ($a['pass'] ?? PHP_INT_MAX) <=> ($b['pass'] ?? PHP_INT_MAX));

        return ['segments' => $segments, 'brackets' => $brackets];
    }

    /** @return list<array<string, mixed>> */
    private function segments(array $workflow): array
    {
        $itemsBySegment = [];
        foreach (($workflow['items'] ?? []) as $item) {
            $itemsBySegment[(int) $item['behaviour_idx']][] = [
                'key' => (string) $item['item_key'],
                'status' => (string) $item['status'],
                'attempts' => (int) $item['attempts'],
            ];
        }

        $segments = [];
        foreach (($workflow['behaviour_configs'] ?? []) as $idx => $config) {
            $items = $itemsBySegment[$idx] ?? [];
            $ghost = $items === [];
            if ($ghost) {
                // Unreached behaviour: cells knowable only when the config
                // carries its batch (items otherwise generate on arrival).
                foreach ((array) ($config['batch'] ?? []) as $key) {
                    $items[] = ['key' => (string) $key, 'status' => 'ghost', 'attempts' => 0];
                }
            }

            $counts = ['done' => 0, 'failed' => 0, 'waiting' => 0, 'pending' => 0];
            foreach ($items as $item) {
                $bucket = match ($item['status']) {
                    'done', 'skipped' => 'done',
                    'failed' => 'failed',
                    'waiting' => 'waiting',
                    default => 'pending',
                };
                $counts[$bucket]++;
            }

            $segments[] = [
                'idx' => $idx,
                'type' => (string) ($config['type'] ?? ''),
                'label' => str_replace('_', ' ', (string) ($config['type'] ?? '')),
                'ghost' => $ghost,
                'total' => count($items),
                'items' => $items,
            ] + $counts;
        }

        return $segments;
    }

    /** @return array<int, array<string, int>> segment idx => item key => cell position */
    private function cell_index(array $segments): array
    {
        $index = [];
        foreach ($segments as $segment) {
            foreach ($segment['items'] as $position => $item) {
                $index[$segment['idx']][$item['key']] = $position;
            }
        }
        return $index;
    }

    /**
     * Flatten each behaviour result + its history chain into execution
     * entries, ascending by time.
     *
     * @return list<array{behaviour_idx: int, ts: int, keys: list<string>, errors: int, status: string}>
     */
    private function execution_entries(array $results): array
    {
        $entries = [];
        foreach ($results as $idx => $result) {
            foreach (array_merge([$result], (array) ($result['history'] ?? [])) as $entry) {
                $entries[] = [
                    'behaviour_idx' => (int) $idx,
                    'ts' => (int) strtotime((string) ($entry['timestamp'] ?? '')),
                    'keys' => array_map('strval', array_merge(
                        (array) ($entry['batch_success'] ?? []),
                        (array) ($entry['batch_error'] ?? []),
                    )),
                    'errors' => count((array) ($entry['batch_error'] ?? [])),
                    'status' => (string) ($entry['status'] ?? ''),
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $entries;
    }

    /** Floor-bind: the latest window starting at-or-before the entry (1-based ordinal). */
    private function bind(int $entryTs, array $windows): ?int
    {
        $ordinal = null;
        foreach ($windows as $i => $window) {
            if ($window['ts'] <= $entryTs + 1) {
                $ordinal = $i + 1;
            }
        }
        return $ordinal;
    }
}
