<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use stdClass;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BatchableBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Integration\IntegrationTestCase;

/**
 * E2E-3 — WorkflowHandler fork: non-contiguous partial failures produce a child workflow
 *          holding all and only the failed items.
 *
 * Scenario:
 *   Parent workflow has ONE BatchableBehaviourConfig step carrying 5 item keys:
 *     item-A (succeeds), item-B (fails), item-C (succeeds), item-D (fails), item-E (succeeds).
 *
 *   After handle_workflow():
 *     - item-A, item-C, item-E → WorkItemStatus::done
 *     - item-B, item-D         → WorkItemStatus::failed
 *     - WorkflowHandler::maybe_fork_or_fail() detects failures and forks.
 *
 *   Assertions:
 *     1. Parent workflow is persisted (not failed, not complete — it was forked).
 *     2. A child workflow exists with root_workflow_id = parent.id.
 *     3. Child workflow's work items are exactly {item-B, item-D} with status=pending.
 *     4. Parent work items item-A, item-C, item-E retain status=done.
 *     5. reschedule() was called for the child (tracked via spy).
 *
 * Extends IntegrationTestCase (outer transaction rollback) because WorkflowHandler
 * does not dispatch ITransactionalCommand — it calls repo->save() directly.
 *
 * NOTE: WorkflowHandler::ensure_work_items() generates items via item_repo->save()
 * which calls wpdb->insert(). These writes survive even if the outer ROLLBACK is
 * attempted (since save() internally issues individual statements, not nested TXs).
 * We therefore DELETE rows explicitly in tearDown using ref_id as discriminator.
 */
final class BehaviourWorkflowForkTest extends IntegrationTestCase
{
    /** Unique ref_id discriminator for all workflows this suite creates. */
    private const REF_ID   = 88881; // arbitrary, unlikely to collide
    private const REF_TYPE = 'ddd_fork_test';

    // $wpdb is inherited as protected from IntegrationTestCase (set in setUp)
    private IDDDConfig $config;
    private IBehaviourWorkflowRepository $workflow_repo;
    private IWorkItemRepository $item_repo;

    // ── Schema setup (DDL auto-commits; run once per suite) ──────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $di = \Tangible\Datastream\WordPress\DI\di();

        require_once dirname(__DIR__, 3) . '/ddd-wordpress/tables.php';
        \TangibleDDD\WordPress\install_behaviour_workflow_tables($di->get(IDDDConfig::class));
        \TangibleDDD\WordPress\install_behaviour_workflow_item_tables($di->get(IDDDConfig::class));

        // Register our test behaviour type so BaseBehaviourConfig::from_json_instance()
        // can reconstitute it from the JSON column.
        BaseBehaviourConfig::register_type('fork_test_batch', ForkTestBatchConfig::class);
    }

    // ── Per-test setup ─────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        $di                  = \Tangible\Datastream\WordPress\DI\di();
        $this->config        = $di->get(IDDDConfig::class);
        $this->workflow_repo = $di->get(IBehaviourWorkflowRepository::class);
        $this->item_repo     = $di->get(IWorkItemRepository::class);
    }

    // ── Per-test teardown ──────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        // ROLLBACK from parent (may be a no-op if inner writes already committed).
        parent::tearDown();

        // Explicit DELETE scoped by ref_id.
        $wf_table   = $this->config->table('behaviour_workflows');
        $item_table = $this->config->table('behaviour_workflow_items');

        // First find all workflow IDs for our ref.
        $wf_ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM `{$wf_table}` WHERE ref_type = %s AND ref_id = %d",
            self::REF_TYPE,
            self::REF_ID
        ));

        if (!empty($wf_ids)) {
            $placeholders = implode(',', array_fill(0, count($wf_ids), '%d'));
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM `{$item_table}` WHERE workflow_id IN ($placeholders)",
                ...$wf_ids
            ));
        }

        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM `{$wf_table}` WHERE ref_type = %s AND ref_id = %d",
            self::REF_TYPE,
            self::REF_ID
        ));
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Non-contiguous partial failures fork correctly into a child workflow.
     *
     * item-A (ok), item-B (fail), item-C (ok), item-D (fail), item-E (ok)
     * → parent forked; child holds item-B + item-D as pending.
     */
    public function test_partial_failures_produce_child_workflow_with_failed_items(): void
    {
        // ── Build config: 5-item batch, with item-B and item-D set to fail ────
        $batch_keys = ['item-A', 'item-B', 'item-C', 'item-D', 'item-E'];
        $fail_keys  = ['item-B', 'item-D'];
        $config     = new ForkTestBatchConfig(batch: $batch_keys, fail_keys: $fail_keys);

        $workflow = new BehaviourWorkflow(
            id:               null,
            ref_id:           self::REF_ID,
            ref_type:         self::REF_TYPE,
            behaviour_configs: [$config],
        );

        // ── Build handler with spy on reschedule() ────────────────────────────
        $rescheduled_workflow_ids = [];

        $handler = new class(
            $this->workflow_repo,
            $this->item_repo,
            $fail_keys,
            $rescheduled_workflow_ids,
        ) extends WorkflowHandler {
            public function __construct(
                IBehaviourWorkflowRepository $workflow_repo,
                IWorkItemRepository          $item_repo,
                private array                $fail_keys,
                private array                &$rescheduled_ids,
            ) {
                parent::__construct($workflow_repo, $item_repo);
            }

            /** Public entry-point so tests can call handle_workflow() without reflection. */
            public function run(BehaviourWorkflow $workflow): void
            {
                $this->handle_workflow($workflow);
            }

            protected function get_workflows(ICommand $command): array
            {
                return [];
            }

            protected function generate_work_items(
                BehaviourWorkflow  $workflow,
                BaseBehaviourConfig $config
            ): WorkItemList {
                /** @var ForkTestBatchConfig $config */
                return new WorkItemList(array_map(
                    fn(string $key) => new WorkItem(
                        id:            null,
                        workflow_id:   0,  // set by ensure_work_items()
                        behaviour_idx: 0,
                        phase:         1,
                        item_key:      $key,
                    ),
                    $config->batch
                ));
            }

            protected function execute_one(
                BaseBehaviourConfig     $config,
                WorkItem                $item,
                ?BehaviourExecutionResult $previous
            ): BehaviourExecutionResult {
                $fail   = in_array($item->item_key, $this->fail_keys, true);
                $build  = BehaviourExecutionResult::builder($config, 1);

                return $fail
                    ? $build(false, 'simulated failure', BehaviourExecutionStatus::failed)
                    : $build(true, 'ok', BehaviourExecutionStatus::completed);
            }

            protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void
            {
                if ($workflow->get_id() !== null) {
                    $this->rescheduled_ids[] = $workflow->get_id();
                }
            }
        };

        // ── Execute ───────────────────────────────────────────────────────────
        $handler->run($workflow);

        // ── 1. Parent workflow was persisted ──────────────────────────────────
        $parent_id = $workflow->get_id();
        $this->assertNotNull($parent_id, 'Parent workflow must be persisted after handle_workflow()');

        // ── 2. Child workflow exists with root_workflow_id = parent.id ────────
        $wf_table = $this->config->table('behaviour_workflows');
        $child_row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$wf_table}` WHERE root_workflow_id = %d LIMIT 1",
            $parent_id
        ));

        $this->assertNotNull(
            $child_row,
            'A child workflow (root_workflow_id = parent) must exist after forking'
        );

        $child_id = (int) $child_row->id;

        // ── 3. Child work items are exactly item-B + item-D, status=pending ───
        $item_table   = $this->config->table('behaviour_workflow_items');
        $child_items  = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM `{$item_table}` WHERE workflow_id = %d ORDER BY item_key ASC",
            $child_id
        ));

        $this->assertCount(2, $child_items, 'Child workflow must have exactly 2 work items');

        $child_keys = array_column($child_items, 'item_key');
        sort($child_keys);
        $this->assertSame(['item-B', 'item-D'], $child_keys, 'Child must hold only the two failed items');

        foreach ($child_items as $item) {
            $this->assertSame(
                'pending',
                $item->status,
                "Child item {$item->item_key} must be reset to pending for retry"
            );
            $this->assertSame(0, (int) $item->attempts, "Child item attempts must be reset to 0");
        }

        // ── 4. Parent items item-A, item-C, item-E retain status=done ─────────
        $parent_items = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM `{$item_table}` WHERE workflow_id = %d ORDER BY item_key ASC",
            $parent_id
        ));

        $done_keys = array_column(
            array_filter($parent_items, fn($r) => $r->status === 'done'),
            'item_key'
        );
        sort($done_keys);
        $this->assertSame(
            ['item-A', 'item-C', 'item-E'],
            $done_keys,
            'Parent must retain done status for the three successful items'
        );

        // ── 5. reschedule() was called for the child ───────────────────────────
        $this->assertContains(
            $child_id,
            $rescheduled_workflow_ids,
            'reschedule() must have been called with the child workflow'
        );
    }

    /**
     * A workflow where ALL items succeed completes normally (no fork).
     */
    public function test_all_succeed_workflow_completes_without_fork(): void
    {
        $batch_keys = ['item-X', 'item-Y', 'item-Z'];
        $config     = new ForkTestBatchConfig(batch: $batch_keys, fail_keys: []);

        $workflow = new BehaviourWorkflow(
            id:               null,
            ref_id:           self::REF_ID,
            ref_type:         self::REF_TYPE,
            behaviour_configs: [$config],
        );

        $rescheduled = [];

        $handler = new class(
            $this->workflow_repo,
            $this->item_repo,
            [],
            $rescheduled,
        ) extends WorkflowHandler {
            public function __construct(
                IBehaviourWorkflowRepository $wf,
                IWorkItemRepository $items,
                private array $fail_keys,
                private array &$rescheduled,
            ) { parent::__construct($wf, $items); }

            public function run(BehaviourWorkflow $workflow): void
            {
                $this->handle_workflow($workflow);
            }

            protected function get_workflows(ICommand $command): array { return []; }

            protected function generate_work_items(
                BehaviourWorkflow $workflow, BaseBehaviourConfig $config
            ): WorkItemList {
                /** @var ForkTestBatchConfig $config */
                return new WorkItemList(array_map(
                    fn(string $k) => new WorkItem(null, 0, 0, 1, $k),
                    $config->batch
                ));
            }

            protected function execute_one(
                BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous
            ): BehaviourExecutionResult {
                return BehaviourExecutionResult::builder($config, 1)(
                    true, 'ok', BehaviourExecutionStatus::completed
                );
            }

            protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void
            {
                $this->rescheduled[] = $workflow->get_id();
            }
        };

        $handler->run($workflow);

        $this->assertTrue($workflow->is_complete(), 'All-success workflow must be complete');
        $this->assertFalse($workflow->is_failed(), 'All-success workflow must not be failed');

        // No child workflows.
        $wf_table  = $this->config->table('behaviour_workflows');
        $child_cnt = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wf_table}` WHERE root_workflow_id = %d",
            $workflow->get_id()
        ));
        $this->assertSame(0, $child_cnt, 'No child workflows should be created on full success');

        // reschedule() should NOT have been called.
        $this->assertEmpty($rescheduled, 'reschedule() must not be called when all items succeed');
    }
}

// ─── Inline test fixtures ────────────────────────────────────────────────────

/**
 * BatchableBehaviourConfig for the fork test.
 *
 * Carries the full list of item keys (batch) plus which ones should fail (fail_keys).
 * fail_keys is stored as meta so it survives JSON serialization.
 */
final class ForkTestBatchConfig extends BatchableBehaviourConfig
{
    public function __construct(
        array                    $batch    = [],
        public readonly array    $fail_keys = [],
    ) {
        parent::__construct(batch: $batch);
    }

    public function get_behaviour_type(): string
    {
        return 'fork_test_batch';
    }

    public function get_default_batch_size(): int
    {
        return 100; // process all items in one chunk
    }

    public function clone_with_batch(array $batch): static
    {
        return new static(batch: $batch, fail_keys: $this->fail_keys);
    }

    protected function serialize_properties(): stdClass
    {
        $std            = parent::serialize_properties();
        $std->fail_keys = $this->fail_keys;
        return $std;
    }

    protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static
    {
        $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
        return new static(
            batch:     isset($data->batch) ? (array) $data->batch : [],
            fail_keys: isset($data->fail_keys) ? (array) $data->fail_keys : [],
        );
    }
}
