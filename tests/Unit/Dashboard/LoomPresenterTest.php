<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\WordPress\Admin\Dashboard\Query\LoomPresenter;

/**
 * The Loom: workflow progress as segments (behaviours with their own item
 * cells) + brackets (pass windows over the cells they executed). Sources:
 * behaviour_results history entries (batch_success/batch_error + timestamp
 * per execution — the pass ledger that was always persisted) bound to pass
 * command windows by floor-timestamp, work items for cell states, config
 * batches for ghost segments.
 */
final class LoomPresenterTest extends TestCase
{
    /** Calm workflow: pass 1 = all of b1 + 2 items of b2 (cut ⌁), pass 2 = rest. */
    public function test_calm_workflow_yields_two_brackets_with_a_cross_segment_span(): void
    {
        $workflow = [
            'id' => 88,
            'current_idx' => 1,
            'behaviour_configs' => [
                ['type' => 'validate_evidence', 'batch' => ['a', 'b']],
                ['type' => 'submit_earnings', 'batch' => ['x', 'y', 'z']],
            ],
            'behaviour_results' => [
                // b1: completed in the first pass (single entry, no history)
                ['type' => 'validate_evidence', 'status' => 'completed', 'timestamp' => '2026-07-25T10:00:04+00:00', 'batch_success' => ['a', 'b'], 'batch_error' => [], 'history' => []],
                // b2: completed in pass 2, history carries pass 1's cut bite
                ['type' => 'submit_earnings', 'status' => 'completed', 'timestamp' => '2026-07-25T10:01:08+00:00', 'batch_success' => ['z'], 'batch_error' => [], 'history' => [
                    ['type' => 'submit_earnings', 'status' => 'batched', 'timestamp' => '2026-07-25T10:00:22+00:00', 'batch_success' => ['x', 'y'], 'batch_error' => [], 'history' => []],
                ]],
            ],
            'items' => [
                ['behaviour_idx' => 0, 'item_key' => 'a', 'status' => 'done', 'attempts' => 1],
                ['behaviour_idx' => 0, 'item_key' => 'b', 'status' => 'done', 'attempts' => 1],
                ['behaviour_idx' => 1, 'item_key' => 'x', 'status' => 'done', 'attempts' => 1],
                ['behaviour_idx' => 1, 'item_key' => 'y', 'status' => 'done', 'attempts' => 1],
                ['behaviour_idx' => 1, 'item_key' => 'z', 'status' => 'done', 'attempts' => 1],
            ],
        ];
        $windows = [
            ['command_id' => 'cmd-p1', 'ts' => strtotime('2026-07-25T10:00:00+00:00'), 'dur_ms' => 24100],
            ['command_id' => 'cmd-p2', 'ts' => strtotime('2026-07-25T10:01:00+00:00'), 'dur_ms' => 11300],
        ];

        $loom = (new LoomPresenter())->present($workflow, $windows);

        $this->assertCount(2, $loom['segments']);
        $this->assertSame('validate evidence', $loom['segments'][0]['label']);
        $this->assertSame(2, $loom['segments'][0]['done']);
        $this->assertSame(['x', 'y', 'z'], array_column($loom['segments'][1]['items'], 'key'));

        $this->assertCount(2, $loom['brackets']);
        [$p1, $p2] = $loom['brackets'];

        $this->assertSame(1, $p1['pass']);
        $this->assertSame('cmd-p1', $p1['command_id']);
        $this->assertSame(['seg' => 0, 'cell' => 0], $p1['from']);
        $this->assertSame(['seg' => 1, 'cell' => 1], $p1['to'], 'pass 1 crossed into b2 and stopped after y');
        $this->assertTrue($p1['cut'], 'b2 entry in pass 1 is batched = stopped with work remaining');

        $this->assertSame(2, $p2['pass']);
        $this->assertSame(['seg' => 1, 'cell' => 2], $p2['from']);
        $this->assertSame(['seg' => 1, 'cell' => 2], $p2['to']);
        $this->assertFalse($p2['cut']);
    }

    public function test_failed_keys_mark_the_bracket_and_survive_alongside_cells(): void
    {
        $workflow = [
            'id' => 89,
            'current_idx' => 0,
            'behaviour_configs' => [['type' => 'notify', 'batch' => ['m', 'n']]],
            'behaviour_results' => [
                ['type' => 'notify', 'status' => 'failed', 'timestamp' => '2026-07-25T11:00:05+00:00', 'batch_success' => ['m'], 'batch_error' => ['n'], 'history' => []],
            ],
            'items' => [
                ['behaviour_idx' => 0, 'item_key' => 'm', 'status' => 'done', 'attempts' => 1],
                ['behaviour_idx' => 0, 'item_key' => 'n', 'status' => 'failed', 'attempts' => 2],
            ],
        ];
        $windows = [['command_id' => 'cmd-f1', 'ts' => strtotime('2026-07-25T11:00:00+00:00'), 'dur_ms' => 5000]];

        $loom = (new LoomPresenter())->present($workflow, $windows);

        $this->assertSame(1, $loom['segments'][0]['failed']);
        $this->assertSame(2, $loom['segments'][0]['items'][1]['attempts']);
        $this->assertSame(1, $loom['brackets'][0]['errors']);
    }

    public function test_unreached_behaviour_renders_ghost_cells_from_the_config_batch(): void
    {
        $workflow = [
            'id' => 90,
            'current_idx' => 0,
            'behaviour_configs' => [
                ['type' => 'validate', 'batch' => ['a']],
                ['type' => 'archive', 'batch' => ['p', 'q', 'r']],
            ],
            'behaviour_results' => [],
            'items' => [
                ['behaviour_idx' => 0, 'item_key' => 'a', 'status' => 'pending', 'attempts' => 0],
            ],
        ];

        $loom = (new LoomPresenter())->present($workflow, []);

        $this->assertFalse($loom['segments'][0]['ghost']);
        $this->assertTrue($loom['segments'][1]['ghost']);
        $this->assertSame(3, $loom['segments'][1]['total'], 'count known from the config batch');
        $this->assertSame('ghost', $loom['segments'][1]['items'][0]['status']);
        $this->assertSame([], $loom['brackets']);
    }

    public function test_execution_with_no_window_binds_to_an_unbound_bracket(): void
    {
        $workflow = [
            'id' => 91,
            'current_idx' => 0,
            'behaviour_configs' => [['type' => 'validate', 'batch' => ['a']]],
            'behaviour_results' => [
                ['type' => 'validate', 'status' => 'completed', 'timestamp' => '2026-07-25T12:00:05+00:00', 'batch_success' => ['a'], 'batch_error' => [], 'history' => []],
            ],
            'items' => [['behaviour_idx' => 0, 'item_key' => 'a', 'status' => 'done', 'attempts' => 1]],
        ];

        $loom = (new LoomPresenter())->present($workflow, []);

        $this->assertCount(1, $loom['brackets']);
        $this->assertNull($loom['brackets'][0]['pass']);
        $this->assertNull($loom['brackets'][0]['command_id']);
    }

    /** Thrash shape (mega-trace #4067): many one-item passes still come out one bracket each. */
    public function test_thrash_history_binds_each_bite_to_its_own_pass(): void
    {
        $workflow = [
            'id' => 92,
            'current_idx' => 0,
            'behaviour_configs' => [['type' => 'review', 'batch' => ['i1', 'i2']]],
            'behaviour_results' => [
                ['type' => 'review', 'status' => 'completed', 'timestamp' => '2026-07-25T13:02:01+00:00', 'batch_success' => [], 'batch_error' => [], 'history' => [
                    ['type' => 'review', 'status' => 'batched', 'timestamp' => '2026-07-25T13:01:01+00:00', 'batch_success' => ['i2'], 'batch_error' => [], 'history' => []],
                    ['type' => 'review', 'status' => 'batched', 'timestamp' => '2026-07-25T13:00:01+00:00', 'batch_success' => ['i1'], 'batch_error' => [], 'history' => []],
                ]],
            ],
            'items' => [
                ['behaviour_idx' => 0, 'item_key' => 'i1', 'status' => 'done', 'attempts' => 1],
                ['behaviour_idx' => 0, 'item_key' => 'i2', 'status' => 'done', 'attempts' => 1],
            ],
        ];
        $windows = [
            ['command_id' => 'p1', 'ts' => strtotime('2026-07-25T13:00:00+00:00'), 'dur_ms' => 1500],
            ['command_id' => 'p2', 'ts' => strtotime('2026-07-25T13:01:00+00:00'), 'dur_ms' => 1500],
            ['command_id' => 'p3', 'ts' => strtotime('2026-07-25T13:02:00+00:00'), 'dur_ms' => 900],
        ];

        $loom = (new LoomPresenter())->present($workflow, $windows);

        $this->assertCount(3, $loom['brackets']);
        $this->assertSame(['p1', 'p2', 'p3'], array_column($loom['brackets'], 'command_id'));
        // The resolve pass (empty batches) still shows: a zero-width bracket on the segment end.
        $this->assertSame([], $loom['brackets'][2]['keys'] ?? [], 'resolve pass carries no keys');
    }
}
