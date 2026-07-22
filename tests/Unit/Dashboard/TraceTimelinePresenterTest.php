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
        self::assertSame(['lms:c:root-a', 'lms:e:evt-1', 'lms:c:after-wait', 'quiz:c:root-b'], array_column($trace['nodes'], 'uid'));
        self::assertSame(60, $trace['nodes'][2]['gap_before']);
        self::assertSame(0.0, $trace['nodes'][3]['start_pct']);
        self::assertSame(['name' => 'mail'], $trace['workflows'][0]['behaviour_configs'][0]);
        self::assertSame(8, $trace['workflows'][0]['id']);
        self::assertSame(['lms', 'quiz'], array_keys($trace['participants']));
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
        self::assertCount(4, array_filter(
            $commands,
            static fn (array $node): bool => $node['gap_before'] !== null,
        ));
    }

    /** @return array<string, mixed> */
    private function command(
        string $id,
        string $name,
        ?string $causeId,
        ?string $causeType,
        string $startedAt,
        int $duration,
    ): array {
        return [
            'command_id' => $id, 'correlation_id' => 'corr-mega', 'command_name' => $name,
            'status' => 'success', 'source' => 'system', 'source_id' => null,
            'causation_id' => $causeId, 'causation_type' => $causeType,
            'duration_ms' => (string) $duration, 'peak_memory_bytes' => '1000',
            'started_at' => $startedAt, 'parameters' => '{}', 'events' => '[]', 'error' => null,
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
