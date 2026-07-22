<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Query\TraceQuery;

final class TraceQueryTest extends TestCase
{
    public function test_it_preserves_causal_nodes_compressed_gaps_and_workflow_overlay(): void
    {
        $db = new ScriptedDatabase();
        $db->resultSets = [
            [[
                'command_id' => 'cmd-root', 'correlation_id' => 'corr', 'command_name' => 'App\\Start',
                'status' => 'success', 'source' => 'user', 'source_id' => '4', 'causation_id' => null,
                'causation_type' => null, 'duration_ms' => '20', 'peak_memory_bytes' => '1000',
                'started_at' => '2026-07-19 10:00:00', 'parameters' => '{}', 'events' => '[]', 'error' => null,
            ], [
                'command_id' => 'cmd-child', 'correlation_id' => 'corr', 'command_name' => 'App\\Continue',
                'status' => 'error', 'source' => 'system', 'source_id' => null, 'causation_id' => 'evt-1',
                'causation_type' => 'integration_event', 'duration_ms' => '50', 'peak_memory_bytes' => '2000',
                'started_at' => '2026-07-19 10:00:05', 'parameters' => '{}', 'events' => '[]',
                'error' => '{"message":"failed"}',
            ]],
            [[
                'event_id' => 'evt-1', 'event_type' => 'ThingStarted', 'status' => 'completed',
                'command_id' => 'cmd-root', 'sequence' => '1', 'attempts' => '1',
                'created_at' => '2026-07-19 10:00:00',
            ]],
            [],
            [[
                'id' => '8', 'ref_id' => '90', 'ref_type' => 'request', 'root_workflow_id' => null,
                'behaviour_configs' => '[{"name":"mail"}]', 'behaviour_results' => '[]',
                'current_idx' => '1', 'current_phase' => '2', 'is_complete' => '0', 'is_failed' => '0',
                'created_at' => '2026-07-19 10:00:00',
            ]],
            [],
        ];

        $trace = (new TraceQuery(new FakeDDDConfig(), $db))->assemble('corr');

        self::assertSame(2, $trace['span_count']);
        self::assertSame(1, $trace['event_count']);
        self::assertSame(1, $trace['workflow_count']);
        self::assertTrue($trace['has_error']);
        // V1 rounds each command's non-zero duration up to a wall-clock second.
        self::assertSame(6000, $trace['total_ms']);
        self::assertSame(['command', 'event', 'command'], array_column($trace['nodes'], 'kind'));
        self::assertSame('test:c:cmd-root', $trace['nodes'][1]['parent']);
        self::assertSame('test:e:evt-1', $trace['nodes'][2]['parent']);
        self::assertSame(5, $trace['nodes'][2]['gap_before']);
        self::assertSame(['name' => 'mail'], $trace['workflows'][0]['behaviour_configs'][0]);
        self::assertSame(8, $trace['workflows'][0]['id']);
        self::assertSame(['test'], array_keys($trace['participants']));
        self::assertCount(5, $db->prepared);
        self::assertSame(['corr'], $db->prepared[0]['args']);
    }
}
