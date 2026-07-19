<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Query\DeadLetterQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\LiveQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\MetricsQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\OutboxQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\ProcessQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\TracesQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\WorkflowQuery;

final class OperationalQueriesTest extends TestCase
{
    public function test_recent_traces_preserve_root_counts_and_in_progress_state(): void
    {
        $db = new ScriptedDatabase();
        $db->resultSets = [
            [['correlation_id' => 'corr', 'first_at' => '2026-07-19 10:00:00', 'last_at' => '2026-07-19 10:00:03', 'spans' => '2', 'errs' => '1']],
            [['correlation_id' => 'corr', 'command_name' => 'App\\RootCommand', 'is_root' => '1', 'started_at' => '2026-07-19 10:00:00']],
            [['correlation_id' => 'corr']],
            [],
            [],
            [['correlation_id' => 'corr', 'n' => '3']],
            [['correlation_id' => 'corr', 'n' => '1']],
            [],
        ];

        $rows = (new TracesQuery(new FakeDDDConfig(), $db))->recent(10);

        self::assertCount(1, $rows);
        self::assertSame('RootCommand', $rows[0]['root_command']);
        self::assertSame(2, $rows[0]['spans']);
        self::assertSame(3, $rows[0]['events']);
        self::assertSame(1, $rows[0]['workflows']);
        self::assertSame(3000, $rows[0]['dur_ms']);
        self::assertTrue($rows[0]['in_progress']);
    }

    public function test_metrics_preserve_v1_operational_shape_and_casts(): void
    {
        $db = new ScriptedDatabase();
        $db->values = [10, 1, 100, 4, 25.4, 80, 3, 2, 1, 1, 2, 35, 5, 6];
        $db->resultSets = [
            [['command_name' => 'Bad', 'correlation_id' => 'c', 'started_at' => 'now']],
            [['id' => '2', 'event_type' => 'E', 'correlation_id' => 'c', 'final_error' => 'x', 'moved_at' => 'now']],
            [['id' => '3', 'process_class' => 'P', 'status' => 'suspended', 'waiting_for' => 'E', 'updated_at' => 'now']],
            [['command_name' => 'App\\Do', 'n' => '8', 'errs' => '2']],
            [['t' => 'wp_test_command_audit', 'row_count' => '20', 'bytes' => '4096']],
        ];

        $metrics = (new MetricsQuery(new FakeDDDConfig(), $db))->overview();

        self::assertSame(10, $metrics['throughput_1m']);
        self::assertSame(96.0, $metrics['success_rate']);
        self::assertSame(25, $metrics['avg_ms']);
        self::assertSame(80, $metrics['p95_ms']);
        self::assertSame(4, $metrics['in_flight']);
        self::assertSame(35, $metrics['outbox_oldest_age_s']);
        self::assertSame(['name' => 'App\\Do', 'n' => 8, 'errs' => 2], $metrics['top_commands'][0]);
        self::assertSame(['t' => 'wp_test_command_audit', 'rows' => 20, 'bytes' => 4096], $metrics['storage'][0]);
        self::assertSame(5, $metrics['proc_active']);
        self::assertSame(6, $metrics['proc_suspended']);
    }

    public function test_live_tail_preserves_cursor_casts_and_depth_counts(): void
    {
        $db = new ScriptedDatabase();
        $db->resultSets = [[[
            'id' => '12', 'command_id' => 'cmd', 'correlation_id' => 'corr', 'command_name' => 'Do',
            'status' => 'success', 'source' => 'system', 'source_id' => null, 'duration_ms' => '9',
            'started_at' => '2026-07-19 10:00:00',
        ]]];
        $db->values = [2, 3];

        $tick = (new LiveQuery(new FakeDDDConfig(), $db))->tick(10);

        self::assertSame(12, $tick['cursor']);
        self::assertSame(12, $tick['rows'][0]['id']);
        self::assertSame(9, $tick['rows'][0]['duration_ms']);
        self::assertSame(['dlq' => 2, 'outbox' => 3], $tick['counts']);
    }

    public function test_process_list_decodes_json_and_paginates(): void
    {
        $db = new ScriptedDatabase();
        $db->values = [1];
        $db->resultSets = [[[
            'id' => '7', 'process_class' => 'P', 'status' => 'suspended', 'step_index' => '2',
            'step_name' => 'wait', 'waiting_for' => 'E', 'match_criteria' => '{"id":4}',
            'business_data' => '{"x":1}', 'steps' => '[{"name":"start"}]', 'correlation_id' => 'c',
            'last_error' => null, 'created_at' => 'a', 'updated_at' => 'b',
        ]]];

        $page = (new ProcessQuery(new FakeDDDConfig(), $db))->list(['status' => 'suspended']);

        self::assertSame(1, $page['total']);
        self::assertSame(7, $page['rows'][0]['id']);
        self::assertSame(2, $page['rows'][0]['step_index']);
        self::assertSame(['id' => 4], $page['rows'][0]['match_criteria']);
        self::assertSame([['name' => 'start']], $page['rows'][0]['steps']);
    }

    public function test_workflow_list_adapts_to_available_columns_and_embeds_items_and_forks(): void
    {
        $db = new ScriptedDatabase();
        $db->values = [1];
        $db->columns = [['id', 'ref_id', 'ref_type', 'root_workflow_id', 'behaviour_configs', 'behaviour_results', 'current_idx', 'current_phase', 'is_complete', 'is_failed', 'created_at']];
        $db->resultSets = [
            [[
                'id' => '8', 'ref_id' => '9', 'ref_type' => 'request', 'root_workflow_id' => null,
                'behaviour_configs' => '[{"name":"mail"}]', 'behaviour_results' => '[]',
                'current_idx' => '1', 'current_phase' => '2', 'is_complete' => '0', 'is_failed' => '0',
                'created_at' => 'now',
            ]],
            [['workflow_id' => '8', 'behaviour_idx' => '1', 'phase' => '2', 'item_key' => 'a', 'status' => 'done', 'attempts' => '1']],
            [['id' => '10', 'root_workflow_id' => '8', 'is_complete' => '1', 'is_failed' => '0', 'current_idx' => '2']],
        ];

        $page = (new WorkflowQuery(new FakeDDDConfig(), $db))->list(['state' => 'running']);

        self::assertSame(8, $page['rows'][0]['id']);
        self::assertNull($page['rows'][0]['meta']);
        self::assertSame('mail', $page['rows'][0]['behaviour_configs'][0]['name']);
        self::assertSame(1, $page['rows'][0]['items'][0]['attempts']);
        self::assertSame(10, $page['rows'][0]['forks'][0]['id']);
        self::assertStringContainsString('ORDER BY created_at DESC', $db->prepared[0]['sql']);
    }

    public function test_outbox_and_dead_letter_lists_preserve_filters_and_integer_fields(): void
    {
        $outboxDb = new ScriptedDatabase();
        $outboxDb->values = [1];
        $outboxDb->resultSets = [[[
            'id' => '4', 'event_type' => 'E', 'status' => 'failed', 'attempts' => '2', 'max_attempts' => '5',
            'correlation_id' => 'c', 'command_id' => 'cmd', 'last_error' => 'x', 'next_attempt_at' => null,
            'created_at' => 'now',
        ]]];
        $outbox = (new OutboxQuery(new FakeDDDConfig(), $outboxDb))->list(['status' => 'failed']);
        self::assertSame(4, $outbox['rows'][0]['id']);
        self::assertSame(2, $outbox['rows'][0]['attempts']);
        self::assertSame(5, $outbox['rows'][0]['max_attempts']);

        $dlqDb = new ScriptedDatabase();
        $dlqDb->values = [1];
        $dlqDb->resultSets = [[[
            'id' => '6', 'event_type' => 'E', 'correlation_id' => 'c', 'command_id' => 'cmd',
            'attempts' => '5', 'final_error' => 'x', 'moved_at' => 'now',
        ]]];
        $dlq = (new DeadLetterQuery(new FakeDDDConfig(), $dlqDb))->list([]);
        self::assertSame(6, $dlq['rows'][0]['id']);
        self::assertSame(5, $dlq['rows'][0]['attempts']);
    }
}
