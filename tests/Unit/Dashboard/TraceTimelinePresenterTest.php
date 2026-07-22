<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Tracing\TraceStitcher;
use TangibleDDD\WordPress\Admin\Dashboard\Query\TraceTimelinePresenter;

final class TraceTimelinePresenterTest extends TestCase
{
    public function test_it_presents_parallel_roots_and_compresses_real_wall_clock_gaps(): void
    {
        $graph = (new TraceStitcher())->stitch([
            [
                'consumer' => ['key' => 'lms', 'label' => 'LMS', 'accent' => '#2271b1', 'ghost' => false],
                'commands' => [
                    $this->command('root-a', 'Lms\\Start', null, null, '2026-07-22 10:00:00', 20),
                    $this->command('after-wait', 'Lms\\Resume', 'evt-1', 'integration_event', '2026-07-22 10:01:00', 50),
                ],
                'events' => [[
                    'event_id' => 'evt-1', 'event_type' => 'Lms\\Started', 'status' => 'completed',
                    'command_id' => 'root-a', 'sequence' => '1', 'attempts' => '1',
                    'created_at' => '2026-07-22 10:00:00',
                ]],
                'processes' => [],
                'workflows' => [[
                    'id' => '8', 'ref_id' => '90', 'ref_type' => 'enrollment', 'root_workflow_id' => null,
                    'behaviour_configs' => '[{"name":"mail"}]', 'behaviour_results' => '[]',
                    'current_idx' => '1', 'current_phase' => '2', 'is_complete' => '0', 'is_failed' => '0',
                    'created_at' => '2026-07-22 10:00:00',
                ]],
            ],
            [
                'consumer' => ['key' => 'quiz', 'label' => 'Quiz', 'accent' => '#a61b1b', 'ghost' => false],
                'commands' => [
                    $this->command('root-b', 'Quiz\\StartAttempt', null, null, '2026-07-22 10:00:02', 10),
                ],
                'events' => [],
                'processes' => [],
                'workflows' => [],
            ],
        ]);

        $trace = (new TraceTimelinePresenter())->present('corr-mega', $graph);

        self::assertSame(3, $trace['span_count']);
        self::assertSame(1, $trace['event_count']);
        self::assertSame(1, $trace['workflow_count']);
        self::assertSame(61000, $trace['total_ms']);
        // Ledger-dense geometry: the fact leaves the row flow and docks on its
        // raising act as a PORT; the subscriber re-points to that act.
        self::assertSame(['lms:c:root-a', 'lms:c:after-wait', 'quiz:c:root-b'], array_column($trace['nodes'], 'uid'));
        self::assertCount(1, $trace['nodes'][0]['ports']);
        self::assertSame('lms:e:evt-1', $trace['nodes'][0]['ports'][0]['uid']);
        self::assertSame('Lms\\Started', $trace['nodes'][0]['ports'][0]['name']);
        self::assertSame('completed', $trace['nodes'][0]['ports'][0]['status']);
        self::assertSame('lms:c:root-a', $trace['nodes'][1]['parent']);
        self::assertSame('Lms\\Started', $trace['nodes'][1]['parent_label'], 'The via-fact stays the label');
        self::assertSame(60, $trace['nodes'][1]['gap_before']);
        self::assertGreaterThan(0.0, $trace['nodes'][2]['start_pct']);
        self::assertLessThan($trace['nodes'][1]['start_pct'], $trace['nodes'][2]['start_pct']);
        self::assertSame([2, 60], array_column($trace['time_markers'], 'elapsed_s'));
        self::assertSame([2, 58], array_column($trace['time_markers'], 'gap_s'));
        self::assertSame(['name' => 'mail'], $trace['workflows'][0]['behaviour_configs'][0]);
        self::assertSame(8, $trace['workflows'][0]['id']);
        self::assertSame(['lms', 'quiz'], array_keys($trace['participants']));
    }

    public function test_fact_raised_at_command_end_does_not_create_a_gap_inside_the_act_bracket(): void
    {
        $graph = (new TraceStitcher())->stitch([[
            'consumer' => ['key' => 'cred', 'label' => 'Cred', 'accent' => '#187c65', 'ghost' => false],
            'commands' => [
                $this->command(
                    'verify-evidence',
                    'Cred\\VerifyCredentialEvidence',
                    null,
                    null,
                    '2026-07-22 11:21:02',
                    1_153,
                    '2026-07-22 11:21:04',
                ),
            ],
            'events' => [
                $this->event('evidence-verified', 'verify-evidence', '2026-07-22 11:21:04'),
            ],
            'processes' => [],
            'workflows' => [],
        ]]);

        $trace = (new TraceTimelinePresenter())->present('corr-mega', $graph);

        self::assertSame([], $trace['time_markers']);
        self::assertCount(1, $trace['nodes'], 'The directly raised fact is a port, not a row');
        self::assertSame('Lms\\Advanced', $trace['nodes'][0]['ports'][0]['name']);
        self::assertSame(1, $trace['event_count'], 'Ports still count as facts');
    }

    public function test_command_caused_by_the_fact_starts_a_new_bracket_after_the_transport_wait(): void
    {
        $graph = (new TraceStitcher())->stitch([[
            'consumer' => ['key' => 'cred', 'label' => 'Cred', 'accent' => '#187c65', 'ghost' => false],
            'commands' => [
                $this->command(
                    'verify-evidence',
                    'Cred\\VerifyCredentialEvidence',
                    null,
                    null,
                    '2026-07-22 11:21:02',
                    1_153,
                    '2026-07-22 11:21:04',
                ),
                $this->command(
                    'react-to-evidence',
                    'Cred\\ReactToVerifiedEvidence',
                    'evidence-verified',
                    'integration_event',
                    '2026-07-22 11:21:34',
                    4,
                    '2026-07-22 11:21:34',
                ),
            ],
            'events' => [
                $this->event('evidence-verified', 'verify-evidence', '2026-07-22 11:21:04'),
            ],
            'processes' => [],
            'workflows' => [],
        ]]);

        $trace = (new TraceTimelinePresenter())->present('corr-mega', $graph);

        self::assertSame([32], array_column($trace['time_markers'], 'elapsed_s'));
        self::assertSame([30], array_column($trace['time_markers'], 'gap_s'));
        // Subscriber re-points to the raising act; the transport wait survives.
        self::assertSame(30, $trace['nodes'][1]['gap_before']);
        self::assertSame('cred:c:verify-evidence', $trace['nodes'][1]['parent']);
        self::assertSame('Lms\\Advanced', $trace['nodes'][1]['parent_label']);
    }

    public function test_command_moments_pass_through_and_an_orphan_fact_stays_a_row(): void
    {
        $command = $this->command('acted', 'Lms\\Act', null, null, '2026-07-22 10:00:00', 5);
        $command['events'] = '[{"name":"thing_done","reactions":[{"handler":"NotifyHandler","duration_ms":3}]}]';

        $graph = (new TraceStitcher())->stitch([[
            'consumer' => ['key' => 'lms', 'label' => 'LMS', 'accent' => '#2271b1', 'ghost' => false],
            'commands' => [$command],
            'events' => [[
                // A flat announce: no raiser recorded, so no command parent —
                // this fact cannot dock and must stay a row.
                'event_id' => 'evt-flat', 'event_type' => 'Lms\\Announced', 'status' => 'pending',
                'command_id' => null, 'sequence' => '1', 'attempts' => '0',
                'created_at' => '2026-07-22 10:00:00',
            ]],
            'processes' => [],
            'workflows' => [],
        ]]);

        $trace = (new TraceTimelinePresenter())->present('corr-mega', $graph);

        $kinds = array_column($trace['nodes'], 'kind');
        self::assertContains('event', $kinds, 'Orphan facts remain rows');
        $commandNode = $trace['nodes'][array_search('command', $kinds, true)];
        self::assertSame('thing_done', $commandNode['moments'][0]['name']);
        self::assertSame('NotifyHandler', $commandNode['moments'][0]['reactions'][0]['handler']);
        self::assertSame([], $commandNode['ports']);
    }

    public function test_elapsed_time_is_cumulative_and_a_two_day_hiatus_is_one_gap(): void
    {
        $graph = (new TraceStitcher())->stitch([[
            'consumer' => ['key' => 'lms', 'label' => 'LMS', 'accent' => '#2271b1', 'ghost' => false],
            'commands' => [
                $this->command('root', 'Lms\\Start', null, null, '2026-07-22 10:00:00', 20),
                $this->command('minute-1', 'Lms\\MinuteOne', 'evt-1', 'integration_event', '2026-07-22 10:01:00', 20),
                $this->command('minute-2', 'Lms\\MinuteTwo', 'evt-2', 'integration_event', '2026-07-22 10:02:00', 20),
                $this->command('minute-3', 'Lms\\MinuteThree', 'evt-3', 'integration_event', '2026-07-22 10:03:00', 20),
                $this->command('wake', 'Lms\\Wake', 'evt-4', 'integration_event', '2026-07-24 10:03:00', 20),
            ],
            'events' => [
                $this->event('evt-1', 'root', '2026-07-22 10:00:00'),
                $this->event('evt-2', 'minute-1', '2026-07-22 10:01:00'),
                $this->event('evt-3', 'minute-2', '2026-07-22 10:02:00'),
                $this->event('evt-4', 'minute-3', '2026-07-22 10:03:00'),
            ],
            'processes' => [],
            'workflows' => [],
        ]]);

        $trace = (new TraceTimelinePresenter())->present('corr-mega', $graph);
        $commands = array_values(array_filter(
            $trace['nodes'],
            static fn (array $node): bool => $node['kind'] === 'command',
        ));

        self::assertSame(
            ['root', 'minute-1', 'minute-2', 'minute-3', 'wake'],
            array_column(array_column($commands, 'raw'), 'command_id'),
        );
        self::assertSame([0, 60, 120, 180, 172_980], array_column($commands, 'elapsed_s'));
        self::assertSame([null, 60, 60, 60, 172_800], array_column($commands, 'gap_before'));
        self::assertSame([60, 120, 180, 172_980], array_column($trace['time_markers'], 'elapsed_s'));
        self::assertSame([60, 60, 60, 172_800], array_column($trace['time_markers'], 'gap_s'));
        self::assertCount(4, $trace['time_markers']);
    }

    public function test_sparse_clock_is_monotonic_across_parallel_branches(): void
    {
        $graph = (new TraceStitcher())->stitch([[
            'consumer' => ['key' => 'lms', 'label' => 'LMS', 'accent' => '#2271b1', 'ghost' => false],
            'commands' => [
                $this->command('root', 'Lms\\Start', null, null, '2026-07-22 10:00:00', 20),
                $this->command('later-shallow', 'Lms\\LaterShallow', 'evt-shallow', 'integration_event', '2026-07-22 10:05:02', 20),
                $this->command('early-branch', 'Lms\\EarlyBranch', 'evt-early', 'integration_event', '2026-07-22 10:01:00', 20),
                $this->command('earlier-deep', 'Lms\\EarlierDeep', 'evt-deep', 'integration_event', '2026-07-22 10:03:49', 20),
            ],
            'events' => [
                $this->event('evt-shallow', 'root', '2026-07-22 10:00:00'),
                $this->event('evt-early', 'root', '2026-07-22 10:00:00'),
                $this->event('evt-deep', 'early-branch', '2026-07-22 10:01:00'),
            ],
            'processes' => [],
            'workflows' => [],
        ]]);

        $trace = (new TraceTimelinePresenter())->present('corr-mega', $graph);

        self::assertArrayHasKey('time_markers', $trace);
        self::assertSame([60, 229, 302], array_column($trace['time_markers'], 'elapsed_s'));
        self::assertSame([60, 169, 73], array_column($trace['time_markers'], 'gap_s'));
        self::assertSame(
            count($trace['time_markers']),
            count(array_unique(array_column($trace['time_markers'], 'start_pct'))),
        );

        $commands = [];
        foreach ($trace['nodes'] as $node) {
            if ($node['kind'] === 'command') {
                $commands[$node['raw']['command_id']] = $node;
            }
        }
        self::assertTrue(
            $commands['early-branch']['start_pct']
                < $commands['earlier-deep']['start_pct']
                && $commands['earlier-deep']['start_pct']
                < $commands['later-shallow']['start_pct'],
            'Horizontal position must follow wall-clock activity across parallel causal branches.',
        );
    }

    /** @return array<string, mixed> */
    private function command(
        string $id,
        string $name,
        ?string $causeId,
        ?string $causeType,
        string $startedAt,
        int $duration,
        ?string $endedAt = null,
    ): array {
        return [
            'command_id' => $id, 'correlation_id' => 'corr-mega', 'command_name' => $name,
            'status' => 'success', 'source' => 'system', 'source_id' => null,
            'causation_id' => $causeId, 'causation_type' => $causeType,
            'duration_ms' => (string) $duration, 'peak_memory_bytes' => '1000',
            'started_at' => $startedAt, 'ended_at' => $endedAt ?? $startedAt,
            'parameters' => '{}', 'events' => '[]', 'error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $commandId, string $createdAt): array
    {
        return [
            'event_id' => $id,
            'event_type' => 'Lms\\Advanced',
            'status' => 'completed',
            'command_id' => $commandId,
            'sequence' => '1',
            'attempts' => '1',
            'created_at' => $createdAt,
        ];
    }
}
