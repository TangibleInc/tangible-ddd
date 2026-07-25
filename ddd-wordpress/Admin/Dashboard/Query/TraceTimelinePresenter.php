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
        foreach ($nodes as $uid => &$node) {
            $node['end_ts'] = $this->nodeEndTimestamp($node);
            $node['bracket'] = $this->bracketId($uid, $node, $nodes);
        }
        unset($node);

        // ── Ledger-dense geometry: facts become PORTS ─────────────────────
        // An event whose parent resolved to a command leaves the row flow and
        // docks on that act; anything the fact caused (subscriber commands,
        // ignited processes) re-points to the act for the tree walk, while
        // parent_label keeps the fact's name as the "via" label. Facts with
        // no resolved raiser (flat announces, ghosts) remain rows.
        $portsByAct = [];
        $rehomed = [];
        foreach ($nodes as $uid => $node) {
            $parent = $node['parent'];
            if ($node['kind'] !== 'event'
                || ! is_string($parent)
                || ! isset($nodes[$parent])
                || $nodes[$parent]['kind'] !== 'command'
            ) {
                continue;
            }
            $portsByAct[$parent][] = [
                'uid' => $uid,
                'id' => $node['id'],
                'name' => $node['name'],
                'status' => $node['status'],
                'accent' => $node['accent'],
                'consumer' => $node['consumer'],
                'consumer_label' => $node['consumer_label'],
                'ts' => $node['ts'],
                'touches' => $node['touches'] ?? [],
                'raw' => $node['raw'],
            ];
            $rehomed[$uid] = $parent;
        }
        foreach ($nodes as $uid => &$node) {
            if (is_string($node['parent'] ?? null) && isset($rehomed[$node['parent']])) {
                $node['parent'] = $rehomed[$node['parent']];
            }
        }
        unset($node);
        foreach (array_keys($rehomed) as $uid) {
            unset($nodes[$uid]);
        }

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
        $walk = function (
            string $uid,
            int $depth,
            ?int $parentEndTimestamp,
            ?string $parentBracket,
        ) use (
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
            $wallGap = $parentEndTimestamp !== null ? max(0, $node['ts'] - $parentEndTimestamp) : 0;
            $sameBracket = $parentBracket !== null && $node['bracket'] === $parentBracket;
            $node['gap_before'] = ! $sameBracket && $parentEndTimestamp !== null && $wallGap >= 2
                ? $wallGap
                : null;
            $node['depth'] = $depth;
            $ordered[] = $node;

            $descendants = $children[$uid] ?? [];
            usort($descendants, $byTimestamp);
            foreach ($descendants as $child) {
                $childDepth = $nodes[$child]['kind'] === 'event' ? $depth : $depth + 1;
                $walk($child, $childDepth, $node['end_ts'], $node['bracket']);
            }
        };
        foreach ($roots as $root) {
            $walk($root, 0, null, null);
        }
        // Unreachable components: a causation CYCLE (e.g. a process "causing"
        // the command whose fact ignited it) leaves a whole branch with no
        // path from any root. Walking leftovers in insertion order would emit
        // children before their parents and steal them from the branch. So:
        // take leftovers in timestamp order, CLIMB each one's parent chain to
        // the top of its component (stopping at a missing, visited, or
        // cycle-repeating parent), and walk the component from there — one
        // coherent subtree, parents above children.
        $leftovers = array_filter(array_keys($nodes), static fn (string $uid): bool => ! isset($visited[$uid]));
        usort($leftovers, $byTimestamp);
        foreach ($leftovers as $uid) {
            if (isset($visited[$uid])) {
                continue;
            }
            $top = $uid;
            $climbed = [$top => true];
            while (true) {
                $parent = $nodes[$top]['parent'] ?? null;
                if (! is_string($parent) || ! isset($nodes[$parent]) || isset($visited[$parent])) {
                    break;
                }
                if (isset($climbed[$parent])) {
                    // Cycle closed: no true top exists. The ts-earliest member
                    // wins — that's the starting node, because leftovers are
                    // walked in timestamp order.
                    $top = $uid;
                    break;
                }
                $top = $parent;
                $climbed[$top] = true;
            }
            $walk($top, 0, null, null);
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
        // Ports are still facts: they count, and their at-rest timestamps can
        // stretch the story's clock (a fact rides its act's bracket but its
        // row may round to the next whole second).
        foreach ($portsByAct as $ports) {
            foreach ($ports as $port) {
                $counts['event']++;
                $maxTimestamp = max($maxTimestamp, (int) $port['ts']);
            }
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
        $commandNodes = array_values(array_filter(
            $graph['nodes'] ?? [],
            static fn (array $node): bool => ($node['kind'] ?? '') === 'command',
        ));
        $workflows = array_map(
            fn (array $workflow): array => $this->workflow($workflow, $commandNodes),
            $graph['workflows'],
        );
        $passByCommand = $this->pass_annotations($workflows);

        $outputNodes = array_map(static function (array $node) use ($totalUnits, $minTimestamp, $portsByAct, $passByCommand): array {
            $node['start_pct'] = round($node['cstart'] / $totalUnits * 100, 2);
            $node['width_pct'] = round(max(($node['cend'] - $node['cstart']) / $totalUnits * 100, 0.6), 2);
            $node['elapsed_s'] = $minTimestamp > 0
                ? max(0, (int) $node['ts'] - $minTimestamp)
                : 0;
            if ($node['kind'] === 'command') {
                // The act's anatomy: docked facts (ports, no fabricated
                // duration) and the ordered domain moments from the audit's
                // events JSON — including PR #39 reactions when recorded.
                $node['ports'] = array_map(static function (array $port): array {
                    unset($port['ts']);
                    return $port;
                }, $portsByAct[$node['uid']] ?? []);
                $moments = $node['raw']['events'] ?? null;
                $node['moments'] = is_array($moments) ? $moments : [];
                if (isset($passByCommand[$node['id']])) {
                    $node['pass'] = $passByCommand[$node['id']];
                }
            }
            unset(
                $node['ts'],
                $node['end_ts'],
                $node['bracket'],
                $node['cstart'],
                $node['cend'],
                $node['raised_by'],
                $node['ignited_by'],
                $node['causation_id'],
                $node['causation_type'],
            );
            return $node;
        }, $ordered);

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

        $previousActivityEnd = null;
        $cursorEnd = 0;
        $positioned = [];
        $markers = [];
        $seenBrackets = [];
        foreach ($groups as $timestamp => $groupIndexes) {
            $continuesBracket = false;
            foreach ($groupIndexes as $index) {
                if (isset($seenBrackets[$ordered[$index]['bracket']])) {
                    $continuesBracket = true;
                    break;
                }
            }

            $gap = $previousActivityEnd !== null && ! $continuesBracket
                ? max(0, $timestamp - $previousActivityEnd)
                : 0;
            $groupStart = $previousActivityEnd === null
                ? 0
                : $cursorEnd + ($gap >= 2 ? 130 : 10);
            $groupEnd = $groupStart;

            if ($previousActivityEnd !== null && $gap >= 2) {
                $markers[] = [
                    'cstart' => $groupStart,
                    'elapsed_s' => max(0, $timestamp - $minTimestamp),
                    'gap_s' => $gap,
                ];
            }

            $groupActivityEnd = $timestamp;
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
                $groupActivityEnd = max($groupActivityEnd, $node['end_ts']);
                $seenBrackets[$node['bracket']] = true;
            }

            $cursorEnd = $groupEnd;
            $previousActivityEnd = $previousActivityEnd === null
                ? $groupActivityEnd
                : max($previousActivityEnd, $groupActivityEnd);
        }

        return [$ordered, $markers];
    }

    /**
     * @param array<string, mixed> $workflow
     * @param list<array<string, mixed>> $spans all command nodes in the trace
     * @return array<string, mixed>
     */
    private function workflow(array $workflow, array $spans = []): array
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

        $workflow['loom'] = (new LoomPresenter())->present(
            [
                'behaviour_configs' => $workflow['behaviour_configs'] ?? [],
                'behaviour_results' => $workflow['behaviour_results'] ?? [],
                'items' => $workflow['items'] ?? [],
            ],
            $this->pass_windows(
                $workflow['id'],
                $workflow['consumer'] ?? null,
                $spans,
                $workflow['root_workflow_id'] !== null,
            ),
        );
        unset($workflow['items']);

        return $workflow;
    }

    /**
     * The in-row trellis annotation: command_id => this pass's slice of the
     * loom, for the chip on the pass row ("p 3/8 · validate 2/2 ⌁").
     *
     * @param list<array<string, mixed>> $workflows presented workflows (with looms)
     * @return array<string, array<string, mixed>>
     */
    private function pass_annotations(array $workflows): array
    {
        $annotations = [];
        foreach ($workflows as $workflow) {
            $loom = $workflow['loom'] ?? null;
            if ($loom === null) {
                continue;
            }
            $bound = array_values(array_filter(
                $loom['brackets'],
                static fn (array $bracket): bool => $bracket['pass'] !== null && $bracket['command_id'] !== null,
            ));
            foreach ($bound as $bracket) {
                $notes = [];
                foreach ($bracket['spans'] as $span) {
                    $segment = $loom['segments'][$span['seg']] ?? null;
                    $notes[] = ($segment['label'] ?? 's' . $span['seg']) . ' ' . $span['count'] . '/' . ($segment['total'] ?? '?');
                }
                $annotations[(string) $bracket['command_id']] = [
                    'wf' => (int) $workflow['id'],
                    'n' => (int) $bracket['pass'],
                    'of' => count($bound),
                    'items' => count($bracket['keys']),
                    'cut' => (bool) $bracket['cut'],
                    'errors' => (int) $bracket['errors'],
                    'note' => $notes === [] ? 'resolve · no items' : implode(' → ', $notes),
                ];
            }
        }
        return $annotations;
    }

    /**
     * Pass windows: this workflow's driving commands. Continuation passes
     * carry workflow_id in their payload; the CREATING pass carries null —
     * it is included when it belongs to the same consumer and precedes the
     * first continuation (there is exactly one creating pass per workflow).
     *
     * @param list<array<string, mixed>> $spans
     * @return list<array{command_id: string, ts: int, dur_ms: int}>
     */
    private function pass_windows(int $workflowId, ?string $consumer, array $spans, bool $forked = false): array
    {
        $windows = [];
        $creators = [];
        $continuationName = null;
        $firstContinuationTs = null;
        foreach ($spans as $span) {
            if (empty($span['is_workflow']) || ($consumer !== null && $span['consumer'] !== $consumer)) {
                continue;
            }
            $parameters = is_array($span['raw']['parameters'] ?? null) ? $span['raw']['parameters'] : [];
            if (! array_key_exists('workflow_id', $parameters)) {
                continue;
            }
            $window = [
                'command_id' => (string) $span['id'],
                'ts' => (int) ($span['ts'] ?? 0),
                'dur_ms' => (int) ($span['dur_ms'] ?? 0),
                'name' => (string) ($span['name'] ?? ''),
            ];
            if ((int) ($parameters['workflow_id'] ?? 0) === $workflowId) {
                $windows[] = $window;
                $continuationName = $window['name'];
                $firstContinuationTs = min($firstContinuationTs ?? PHP_INT_MAX, $window['ts']);
            } elseif (($parameters['workflow_id'] ?? null) === null) {
                $creators[] = $window;
            }
        }
        // The creating pass carries workflow_id null — with several workflows
        // in one consumer, only the COMMAND NAME (shared with continuations)
        // discriminates. Earliest same-named creator preceding the first
        // continuation wins; without continuations, fall back to earliest.
        // A FORKED workflow has no creating command at all (it is born inside
        // the parent's pass) — never attribute one.
        if ($forked) {
            $creators = [];
        }
        usort($creators, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        foreach ($creators as $creator) {
            if ($continuationName !== null && $creator['name'] !== $continuationName) {
                continue;
            }
            if ($firstContinuationTs === null || $creator['ts'] <= $firstContinuationTs) {
                $windows[] = $creator;
            }
            break;
        }

        return array_map(static function (array $window): array {
            unset($window['name']);
            return $window;
        }, $windows);
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

    /** @param array<string, mixed> $node */
    private function nodeEndTimestamp(array $node): int
    {
        if ($node['kind'] !== 'command') {
            return (int) $node['ts'];
        }

        $endedAt = $node['raw']['ended_at'] ?? null;
        $endedTimestamp = $endedAt ? (int) strtotime((string) $endedAt . ' UTC') : 0;

        return max((int) $node['ts'], $endedTimestamp);
    }

    /** @param array<string, mixed> $node @param array<string, array<string, mixed>> $nodes */
    private function bracketId(string $uid, array $node, array $nodes): string
    {
        $parent = $node['parent'] ?? null;
        if ($node['kind'] === 'event'
            && is_string($parent)
            && isset($nodes[$parent])
            && $nodes[$parent]['kind'] === 'command'
        ) {
            return $parent;
        }

        return $uid;
    }
}
