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
}
