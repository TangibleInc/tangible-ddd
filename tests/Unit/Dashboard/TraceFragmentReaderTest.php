<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\ConsumerDefinition;
use TangibleDDD\WordPress\Admin\Dashboard\Query\TraceFragmentReader;

final class TraceFragmentReaderTest extends TestCase
{
    public function test_it_reads_one_consumer_owned_fragment_including_process_ignition(): void
    {
        $db = new ScriptedDatabase();
        $db->resultSets = [
            [[
                'command_id' => 'cmd-1', 'correlation_id' => 'corr', 'command_name' => 'Lms\\CompleteCourse',
                'status' => 'success', 'source' => 'user', 'source_id' => '4', 'causation_id' => null,
                'causation_type' => null, 'duration_ms' => '20', 'peak_memory_bytes' => '1000',
                'started_at' => '2026-07-22 10:00:00', 'parameters' => '{}', 'events' => '[]', 'error' => null,
            ]],
            [[
                'event_id' => 'evt-1', 'event_type' => 'Lms\\CourseCompleted', 'status' => 'completed',
                'command_id' => 'cmd-1', 'sequence' => '1', 'attempts' => '1',
                'created_at' => '2026-07-22 10:00:01',
            ]],
            [[
                'id' => '7', 'process_class' => 'Lms\\CompletionProcess', 'status' => 'running',
                'step_name' => 'award', 'waiting_for' => null, 'ignited_by_event_id' => 'evt-1',
                'created_at' => '2026-07-22 10:00:01', 'updated_at' => '2026-07-22 10:00:01',
            ]],
            [],
        ];
        $consumer = new ConsumerDefinition(
            'lms',
            'Learning',
            static fn (): FakeDDDConfig => new FakeDDDConfig(),
            false,
            '#2271b1',
        );

        $fragment = (new TraceFragmentReader($consumer, $db))->read('corr');

        self::assertSame([
            'key' => 'lms',
            'label' => 'Learning',
            'accent' => '#2271b1',
            'ghost' => false,
        ], $fragment['consumer']);
        self::assertSame('evt-1', $fragment['processes'][0]['ignited_by_event_id']);
        self::assertCount(4, $db->prepared);
        self::assertStringContainsString('wp_test_long_processes', $db->prepared[2]['sql']);
        self::assertSame(['corr'], $db->prepared[3]['args']);
    }

    public function test_it_reports_and_skips_a_missing_consumer_table(): void
    {
        $db = new ScriptedDatabase();
        $db->missingTables = ['wp_test_behaviour_workflows'];
        $db->resultSets = [[], [], []];
        $consumer = new ConsumerDefinition(
            'lms',
            'Learning',
            static fn (): FakeDDDConfig => new FakeDDDConfig(),
        );

        $fragment = (new TraceFragmentReader($consumer, $db))->read('corr');

        self::assertSame([], $fragment['workflows']);
        self::assertSame('missing_table', $fragment['warnings'][0]['code']);
        self::assertSame('behaviour_workflows', $fragment['warnings'][0]['table']);
        self::assertCount(3, $db->prepared);
    }
}
