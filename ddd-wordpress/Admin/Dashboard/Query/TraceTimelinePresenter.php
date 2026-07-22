<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

final class TraceTimelinePresenter
{
    /**
     * @param array<string, mixed> $graph
     * @return array<string, mixed>
     */
    public function present(string $correlationId, array $graph): array
    {
        $nodes = $graph['nodes'];
        $children = [];
        $roots = [];
        $order = array_flip(array_keys($nodes));

        foreach ($nodes as $uid => $node) {
            if ($node['parent'] !== null && isset($nodes[$node['parent']])) {
                $children[$node['parent']][] = $uid;
            } else {
                $roots[] = $uid;
            }
        }

        $byTimestamp = static function (string $left, string $right) use ($nodes, $order): int {
            return ($nodes[$left]['ts'] <=> $nodes[$right]['ts']) ?: ($order[$left] <=> $order[$right]);
        };
        usort($roots, $byTimestamp);

        $ordered = [];
        $visited = [];
        $walk = function (string $uid, int $depth, ?int $parentTimestamp) use (
            &$walk,
            &$ordered,
            &$visited,
            $children,
            $nodes,
            $byTimestamp,
        ): void {
            if (isset($visited[$uid])) {
                return;
            }
            $visited[$uid] = true;
            $node = $nodes[$uid];
            $wallGap = $parentTimestamp !== null ? max(0, $node['ts'] - $parentTimestamp) : 0;
            $node['gap_before'] = $parentTimestamp !== null && $wallGap >= 2 ? $wallGap : null;
            $node['depth'] = $depth;
            $ordered[] = $node;

            $descendants = $children[$uid] ?? [];
            usort($descendants, $byTimestamp);
            foreach ($descendants as $child) {
                $childDepth = $nodes[$child]['kind'] === 'event' ? $depth : $depth + 1;
                $walk($child, $childDepth, $node['ts']);
            }
        };
        foreach ($roots as $root) {
            $walk($root, 0, null);
        }
        foreach (array_keys($nodes) as $uid) {
            if (! isset($visited[$uid])) {
                $walk($uid, 0, null);
            }
        }

        $minTimestamp = PHP_INT_MAX;
        $maxTimestamp = 0;
        $maxDuration = 0;
        $hasError = false;
        $startedAt = null;
        $counts = ['command' => 0, 'event' => 0, 'process' => 0];
        foreach ($nodes as $node) {
            if ($node['unresolved']) {
                continue;
            }
            $counts[$node['kind']]++;
            $started = $node['ts'];
            $end = $started;
            if ($node['kind'] === 'command') {
                $duration = (int) $node['dur_ms'];
                $maxDuration = max($maxDuration, $duration);
                $end += (int) ceil($duration / 1000);
                $hasError = $hasError || $node['status'] === 'error';
            } elseif ($node['kind'] === 'process') {
                $updated = $node['raw']['updated_at'] ?? null;
                $end = $updated ? (int) strtotime((string) $updated . ' UTC') : $started;
            }
            if ($started < $minTimestamp) {
                $minTimestamp = $started;
                $startedAt = $this->nodeStartedAt($node);
            }
            $maxTimestamp = max($maxTimestamp, $end);
        }
        if ($minTimestamp === PHP_INT_MAX) {
            $minTimestamp = 0;
        }

        [$ordered, $timeMarkers] = $this->layoutByActivity($ordered, $minTimestamp);

        $totalUnits = 1;
        foreach ($ordered as $node) {
            $totalUnits = max($totalUnits, $node['cend']);
        }
        $totalUnits += 110;
        $timeMarkers = array_map(static function (array $marker) use ($totalUnits): array {
            $marker['start_pct'] = round($marker['cstart'] / $totalUnits * 100, 2);
            unset($marker['cstart']);
            return $marker;
        }, $timeMarkers);
        $outputNodes = array_map(static function (array $node) use ($totalUnits, $minTimestamp): array {
            $node['start_pct'] = round($node['cstart'] / $totalUnits * 100, 2);
            $node['width_pct'] = round(max(($node['cend'] - $node['cstart']) / $totalUnits * 100, 0.6), 2);
            $node['elapsed_s'] = $minTimestamp > 0
                ? max(0, (int) $node['ts'] - $minTimestamp)
                : 0;
            unset(
                $node['ts'],
                $node['cstart'],
                $node['cend'],
                $node['raised_by'],
                $node['ignited_by'],
                $node['causation_id'],
                $node['causation_type'],
            );
            return $node;
        }, $ordered);

        $workflows = array_map([$this, 'workflow'], $graph['workflows']);

        return [
            'correlation_id' => $correlationId,
            'span_count' => $counts['command'],
            'event_count' => $counts['event'],
            'process_count' => $counts['process'],
            'workflow_count' => count($workflows),
            'total_ms' => max(($maxTimestamp - $minTimestamp) * 1000, $maxDuration),
            'max_dur_ms' => $maxDuration,
            'started_at' => $startedAt,
            'has_error' => $hasError,
            'nodes' => $outputNodes,
            'time_markers' => $timeMarkers,
            'workflows' => $workflows,
            'participants' => $graph['participants'],
            'warnings' => $graph['warnings'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $ordered
     * @return array{list<array<string, mixed>>, list<array<string, int>>}
     */
    private function layoutByActivity(array $ordered, int $minTimestamp): array
    {
        $indexes = array_keys($ordered);
        usort($indexes, static function (int $left, int $right) use ($ordered): int {
            return ($ordered[$left]['ts'] <=> $ordered[$right]['ts']) ?: ($left <=> $right);
        });

        $groups = [];
        foreach ($indexes as $index) {
            $groups[(int) $ordered[$index]['ts']][] = $index;
        }

        $previousTimestamp = null;
        $cursorEnd = 0;
        $positioned = [];
        $markers = [];
        foreach ($groups as $timestamp => $groupIndexes) {
            $gap = $previousTimestamp !== null ? max(0, $timestamp - $previousTimestamp) : 0;
            $groupStart = $previousTimestamp === null
                ? 0
                : $cursorEnd + ($gap >= 2 ? 130 : 10);
            $groupEnd = $groupStart;

            if ($previousTimestamp !== null && $gap >= 2) {
                $markers[] = [
                    'cstart' => $groupStart,
                    'elapsed_s' => max(0, $timestamp - $minTimestamp),
                    'gap_s' => $gap,
                ];
            }

            foreach ($groupIndexes as $index) {
                $node = $ordered[$index];
                $start = $groupStart;
                $parent = $node['parent'] ?? null;
                if (is_string($parent)
                    && isset($positioned[$parent])
                    && $positioned[$parent]['ts'] === $timestamp
                ) {
                    $start = max($start, $positioned[$parent]['end'] + 10);
                }
                $end = $start + max((int) $node['dur_ms'], 16);
                $ordered[$index]['cstart'] = $start;
                $ordered[$index]['cend'] = $end;
                $positioned[$node['uid']] = ['ts' => $timestamp, 'end' => $end];
                $groupEnd = max($groupEnd, $end);
            }

            $cursorEnd = $groupEnd;
            $previousTimestamp = $timestamp;
        }

        return [$ordered, $markers];
    }

    /** @param array<string, mixed> $workflow @return array<string, mixed> */
    private function workflow(array $workflow): array
    {
        foreach (['behaviour_configs', 'behaviour_results'] as $column) {
            $value = $workflow[$column] ?? null;
            $workflow[$column] = $value !== null && $value !== '' ? json_decode((string) $value, true) : null;
        }
        foreach (['id', 'ref_id', 'current_idx', 'current_phase', 'is_complete', 'is_failed'] as $column) {
            $workflow[$column] = (int) ($workflow[$column] ?? 0);
        }
        $root = $workflow['root_workflow_id'] ?? null;
        $workflow['root_workflow_id'] = $root !== null && $root !== '' ? (int) $root : null;
        return $workflow;
    }

    /** @param array<string, mixed> $node */
    private function nodeStartedAt(array $node): ?string
    {
        return match ($node['kind']) {
            'command' => $node['raw']['started_at'] ?? null,
            'event' => $node['raw']['created_at'] ?? null,
            'process' => $node['raw']['created_at'] ?? null,
            default => null,
        };
    }
}
