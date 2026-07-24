<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Framework;

use stdClass;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BatchableBehaviourConfig;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Integration\IntegrationTestCase;

/**
 * Schema v7: workflow meta lives in a side table ({prefix}_behaviour_workflows_meta,
 * WP-meta idiom, cred-shaped), not in the JSON `meta` column. The column goes
 * write-dead (kept for old rows; the v7 migration backfills it into rows).
 *
 * Doctrine (owner ruling 2026-07-24): identity lives on the row/envelope/scope —
 * correlation_id is a stamped COLUMN and must never be duplicated into meta.
 */
final class BehaviourWorkflowMetaTableTest extends IntegrationTestCase
{
    private const REF_ID   = 424242;
    private const REF_TYPE = 'ddd_meta_test';

    private IDDDConfig $config;
    private IBehaviourWorkflowRepository $workflow_repo;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $di = \Tangible\Datastream\WordPress\DI\di();

        require_once dirname(__DIR__, 3) . '/ddd-wordpress/tables.php';
        \TangibleDDD\WordPress\install_behaviour_workflow_tables($di->get(IDDDConfig::class));
        \TangibleDDD\WordPress\install_behaviour_workflow_meta_table($di->get(IDDDConfig::class));

        BatchableBehaviourConfig::register_type('meta_test_batch', MetaTestBatchConfig::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $di                  = \Tangible\Datastream\WordPress\DI\di();
        $this->config        = $di->get(IDDDConfig::class);
        $this->workflow_repo = $di->get(IBehaviourWorkflowRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $wf_table   = $this->config->table('behaviour_workflows');
        $meta_table = $this->config->table('behaviour_workflows_meta');

        $wf_ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM `{$wf_table}` WHERE ref_type = %s",
            self::REF_TYPE
        ));

        if (!empty($wf_ids)) {
            $in = implode(',', array_map('intval', $wf_ids));
            $this->wpdb->query("DELETE FROM `{$meta_table}` WHERE id IN ({$in})");
            $this->wpdb->query("DELETE FROM `{$wf_table}` WHERE id IN ({$in})");
        }

        Correlation::reset();
    }

    // ── Write path ─────────────────────────────────────────────────────────

    public function test_save_writes_meta_rows_and_leaves_the_json_column_dead(): void
    {
        $workflow = $this->new_workflow(meta: [
            'journey_id'   => 'journey-abc',
            'learner_id'   => 71180,
            'portfolio_id' => 'portfolio-xyz',
        ]);

        $this->workflow_repo->save($workflow);

        $rows = $this->meta_rows((int) $workflow->get_id());
        $this->assertSame(
            [
                'journey_id'   => 'journey-abc',
                'learner_id'   => '71180',
                'portfolio_id' => 'portfolio-xyz',
            ],
            $rows,
            'meta persists as side-table rows, stringly-typed at rest (WP idiom)'
        );

        $json_column = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta FROM `{$this->config->table('behaviour_workflows')}` WHERE id = %d",
            $workflow->get_id()
        ));
        $this->assertNull($json_column, 'the JSON meta column is write-dead');
    }

    public function test_resave_rewrites_rows_without_duplicating(): void
    {
        $workflow = $this->new_workflow(meta: ['journey_id' => 'journey-abc', 'attempt' => 1]);
        $this->workflow_repo->save($workflow);
        $this->workflow_repo->save($workflow);

        $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM `{$this->config->table('behaviour_workflows_meta')}` WHERE id = %d",
            $workflow->get_id()
        ));
        $this->assertSame(2, $count, 'one row per key, no duplicates across saves');
    }

    public function test_non_scalar_meta_values_round_trip_as_json(): void
    {
        $workflow = $this->new_workflow(meta: ['shape' => ['a' => 1, 'b' => [2, 3]]]);
        $this->workflow_repo->save($workflow);

        $rows = $this->meta_rows((int) $workflow->get_id());
        $this->assertSame('{"a":1,"b":[2,3]}', $rows['shape'], 'non-scalars stored as JSON text');

        $loaded = $this->workflow_repo->get_by_id((int) $workflow->get_id());
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $loaded->get_meta('shape'), 'and decoded on hydration');
    }

    public function test_correlation_is_stamped_on_the_row_never_into_meta(): void
    {
        $workflow = $this->new_workflow(meta: ['journey_id' => 'journey-abc']);

        $ctx = new TraceContext('meta-test-correlation-000000000000');
        Correlation::within($ctx, fn () => $this->workflow_repo->save($workflow));

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT correlation_id FROM `{$this->config->table('behaviour_workflows')}` WHERE id = %d",
            $workflow->get_id()
        ));
        $this->assertSame('meta-test-correlation-000000000000', $row->correlation_id, 'column stamped from scope');

        $this->assertArrayNotHasKey(
            'correlation_id',
            $this->meta_rows((int) $workflow->get_id()),
            'identity lives on the row, never in meta (unlike the legacy cred writer)'
        );
    }

    // ── Read path ──────────────────────────────────────────────────────────

    public function test_get_by_id_hydrates_meta_from_the_side_table(): void
    {
        $workflow = $this->new_workflow(meta: ['journey_id' => 'journey-abc', 'learner_id' => 71180]);
        $this->workflow_repo->save($workflow);

        $loaded = $this->workflow_repo->get_by_id((int) $workflow->get_id());

        $this->assertSame('journey-abc', $loaded->get_meta('journey_id'));
        $this->assertSame('71180', $loaded->get_meta('learner_id'), 'scalar values come back stringly');
    }

    public function test_get_by_ref_id_hydrates_meta_for_every_workflow(): void
    {
        $first  = $this->new_workflow(meta: ['slot' => 'one']);
        $second = $this->new_workflow(meta: ['slot' => 'two']);
        $this->workflow_repo->save($first);
        $this->workflow_repo->save($second);

        $loaded = $this->workflow_repo->get_by_ref_id(self::REF_ID, self::REF_TYPE);

        $this->assertCount(2, $loaded);
        $this->assertSame('one', $loaded[0]->get_meta('slot'));
        $this->assertSame('two', $loaded[1]->get_meta('slot'));
    }

    public function test_legacy_row_with_json_column_and_no_meta_rows_still_hydrates(): void
    {
        // A pre-v7 row the backfill has not touched (resilience, not a lane).
        $wf_table = $this->config->table('behaviour_workflows');
        $this->wpdb->insert($wf_table, [
            'ref_id'            => self::REF_ID,
            'ref_type'          => self::REF_TYPE,
            'behaviour_configs' => '[]',
            'behaviour_results' => '[]',
            'meta'              => '{"journey_id":"legacy-journey"}',
            'created_at'        => gmdate('Y-m-d H:i:s'),
            'updated_at'        => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->wpdb->insert_id;

        $loaded = $this->workflow_repo->get_by_id($id);

        $this->assertSame('legacy-journey', $loaded->get_meta('journey_id'), 'JSON column is the fallback for unbackfilled rows');
    }

    // ── v7 migration backfill ──────────────────────────────────────────────

    public function test_v7_backfill_pivots_json_meta_into_rows(): void
    {
        $wf_table = $this->config->table('behaviour_workflows');
        $this->wpdb->insert($wf_table, [
            'ref_id'            => self::REF_ID,
            'ref_type'          => self::REF_TYPE,
            'behaviour_configs' => '[]',
            'behaviour_results' => '[]',
            'meta'              => '{"journey_id":"backfill-journey","forked_from":2125}',
            'created_at'        => gmdate('Y-m-d H:i:s'),
            'updated_at'        => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->wpdb->insert_id;

        require_once dirname(__DIR__, 3) . '/ddd-wordpress/migrations.php';
        $migrations = \TangibleDDD\WordPress\ddd_explicit_migrations();
        $this->assertArrayHasKey(7, $migrations, 'v7 exists');
        $migrations[7]($this->config);

        $this->assertSame(
            ['forked_from' => '2125', 'journey_id' => 'backfill-journey'],
            $this->meta_rows($id),
            'JSON column pivoted into side-table rows'
        );

        // Idempotent: running again neither duplicates nor errors.
        $migrations[7]($this->config);
        $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM `{$this->config->table('behaviour_workflows_meta')}` WHERE id = %d",
            $id
        ));
        $this->assertSame(2, $count);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function new_workflow(array $meta): BehaviourWorkflow
    {
        return new BehaviourWorkflow(
            id: null,
            ref_id: self::REF_ID,
            ref_type: self::REF_TYPE,
            behaviour_configs: [new MetaTestBatchConfig(['x'])],
            meta: $meta,
        );
    }

    /** @return array<string, string> meta_key => meta_value, key-sorted */
    private function meta_rows(int $workflow_id): array
    {
        $meta_table = $this->config->table('behaviour_workflows_meta');
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT meta_key, meta_value FROM `{$meta_table}` WHERE id = %d",
            $workflow_id
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[$row->meta_key] = $row->meta_value;
        }
        ksort($out);
        return $out;
    }
}

final class MetaTestBatchConfig extends BatchableBehaviourConfig
{
    public function get_behaviour_type(): string
    {
        return 'meta_test_batch';
    }

    public function get_default_batch_size(): int
    {
        return 100;
    }

    public function clone_with_batch(array $batch): static
    {
        return new static(batch: $batch);
    }

    protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static
    {
        $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
        return new static(isset($data->batch) ? (array) $data->batch : []);
    }
}
