<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Config;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\ActionDispatcher;
use TangibleDDD\WordPress\Admin\Dashboard\AdminPage;
use TangibleDDD\WordPress\Admin\Dashboard\ConsumerCatalog;
use TangibleDDD\WordPress\Admin\Dashboard\HeartbeatController;
use TangibleDDD\WordPress\Admin\Dashboard\RestController;

final class WordPressBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        global $_test_rest_routes, $_test_actions, $_test_filters, $_test_management_pages,
            $_test_removed_submenus, $_test_enqueued_scripts, $_test_enqueued_styles,
            $_test_inline_scripts, $_test_current_user_can;
        $_test_rest_routes = [];
        $_test_actions = [];
        $_test_filters = [];
        $_test_management_pages = [];
        $_test_removed_submenus = [];
        $_test_enqueued_scripts = [];
        $_test_enqueued_styles = [];
        $_test_inline_scripts = [];
        $_test_current_user_can = true;
    }

    public function test_rest_controller_registers_and_overrides_all_v1_routes(): void
    {
        global $_test_rest_routes;
        [$catalog, $db] = $this->catalog();
        $controller = new RestController($catalog, $db, new ActionDispatcher(static fn (): null => null));

        $controller->registerRoutes();

        self::assertCount(11, $_test_rest_routes);
        self::assertSame([
            'tangible-ddd/v1/audit',
            'tangible-ddd/v1/trace/(?P<corr>[A-Za-z0-9\-]+)',
            'tangible-ddd/v1/traces',
            'tangible-ddd/v1/biographies',
            'tangible-ddd/v1/biography',
            'tangible-ddd/v1/overview',
            'tangible-ddd/v1/processes',
            'tangible-ddd/v1/workflows',
            'tangible-ddd/v1/outbox',
            'tangible-ddd/v1/dlq',
            'tangible-ddd/v1/actions/(?P<action>[a-z_]+)',
        ], array_keys($_test_rest_routes));
        foreach ($_test_rest_routes as $route) {
            self::assertTrue($route['override']);
            self::assertTrue(($route['args']['permission_callback'])());
        }
    }

    public function test_audit_route_adapts_request_to_the_tested_query(): void
    {
        global $_test_rest_routes;
        [$catalog, $db] = $this->catalog();
        $db->values = [1];
        $db->resultSets = [[[
            'command_id' => 'cmd', 'correlation_id' => 'corr', 'command_name' => 'Do', 'status' => 'success',
            'source' => 'system', 'source_id' => null, 'causation_id' => null, 'causation_type' => null,
            'duration_ms' => '3', 'peak_memory_bytes' => '1', 'started_at' => 'now', 'ended_at' => 'now',
            'parameters' => '{}', 'events' => '[]', 'error' => null,
        ]]];
        (new RestController($catalog, $db, new ActionDispatcher(static fn (): null => null)))->registerRoutes();

        $callback = $_test_rest_routes['tangible-ddd/v1/audit']['args']['callback'];
        $response = $callback(new \WP_REST_Request(['consumer' => 'test', 'status' => 'success']));

        self::assertSame(1, $response['total']);
        self::assertSame('cmd', $response['rows'][0]['command_id']);
        self::assertSame(3, $response['rows'][0]['duration_ms']);
    }

    public function test_trace_route_fans_one_correlation_out_across_consumers(): void
    {
        [$catalog, $db] = $this->catalog();
        $db->resultSets = [
            [[
                'command_id' => 'cmd-root', 'correlation_id' => 'corr', 'command_name' => 'Test\\Start',
                'status' => 'success', 'source' => 'system', 'source_id' => null, 'causation_id' => null,
                'causation_type' => null, 'duration_ms' => '3', 'peak_memory_bytes' => '1',
                'started_at' => '2026-07-22 10:00:00', 'parameters' => '{}', 'events' => '[]', 'error' => null,
            ]],
            [[
                'event_id' => 'evt-1', 'event_type' => 'Test\\Started', 'status' => 'completed',
                'command_id' => 'cmd-root', 'sequence' => '1', 'attempts' => '1',
                'created_at' => '2026-07-22 10:00:00',
            ]],
            [],
            [],
            [],
            [[
                'command_id' => 'cmd-self', 'correlation_id' => 'corr', 'command_name' => 'Framework\\React',
                'status' => 'success', 'source' => 'system', 'source_id' => null, 'causation_id' => 'evt-1',
                'causation_type' => 'integration_event', 'duration_ms' => '2', 'peak_memory_bytes' => '1',
                'started_at' => '2026-07-22 10:00:01', 'parameters' => '{}', 'events' => '[]', 'error' => null,
            ]],
            [],
            [],
            [],
            [],
        ];
        $controller = new RestController($catalog, $db, new ActionDispatcher(static fn (): null => null));

        $response = $controller->trace(new \WP_REST_Request(['consumer' => 'test', 'corr' => 'corr']));

        self::assertSame(2, $response['span_count']);
        self::assertSame('test:e:evt-1', $response['nodes'][2]['parent']);
        self::assertTrue($response['nodes'][2]['cross_consumer']);
        self::assertSame(['test', 'tangible_ddd'], array_keys($response['participants']));
    }

    public function test_biographies_route_adapts_finder_filters_to_the_consumer_query(): void
    {
        global $_test_rest_routes;
        [$catalog, $db] = $this->catalog();
        $db->values = [1];
        $db->resultSets = [[[
            'aggregate' => 'acme.license', 'aggregate_id' => '42', 'touch_count' => '3',
            'first_version' => '1', 'last_version' => '3', 'first_at' => '2026-07-20 10:00:00',
            'last_at' => '2026-07-22 10:00:00', 'last_op' => 'updated',
        ]]];
        (new RestController($catalog, $db, new ActionDispatcher(static fn (): null => null)))->registerRoutes();

        $callback = $_test_rest_routes['tangible-ddd/v1/biographies']['args']['callback'];
        $response = $callback(new \WP_REST_Request([
            'consumer' => 'test', 'search' => 'license', 'page' => 1, 'per_page' => 12,
        ]));

        self::assertTrue($response['available']);
        self::assertSame(1, $response['total']);
        self::assertSame('acme.license', $response['rows'][0]['aggregate']);
        self::assertSame(['%license%', '%license%'], $db->prepared[1]['args']);
        self::assertSame(12, $response['per_page']);
    }

    public function test_biography_route_requires_and_reads_exact_aggregate_identity(): void
    {
        global $_test_rest_routes;
        [$catalog, $db] = $this->catalog();
        $db->resultSets = [[[
            'id' => '1', 'aggregate' => 'acme.license', 'aggregate_id' => '42', 'op' => 'created',
            'version' => '1', 'event_name' => 'license_issued', 'event_id' => 'evt-1',
            'command_id' => 'cmd-1', 'correlation_id' => 'corr-1', 'occurred_at' => '2026-07-22 10:00:00',
            'command_name' => 'Acme\\IssueLicense', 'command_status' => 'success', 'source' => 'user',
            'duration_ms' => '4', 'started_at' => '2026-07-22 10:00:00',
            'event_type' => 'Acme\\LicenseIssued', 'event_status' => 'completed',
            'processed_at' => '2026-07-22 10:00:01',
        ]]];
        (new RestController($catalog, $db, new ActionDispatcher(static fn (): null => null)))->registerRoutes();

        $callback = $_test_rest_routes['tangible-ddd/v1/biography']['args']['callback'];
        $missing = $callback(new \WP_REST_Request(['consumer' => 'test']));
        $response = $callback(new \WP_REST_Request([
            'consumer' => 'test', 'aggregate' => 'acme.license', 'aggregate_id' => '42',
        ]));

        self::assertInstanceOf(\WP_Error::class, $missing);
        self::assertSame('tddd_bad_biography', $missing->get_error_code());
        self::assertSame('acme.license', $response['aggregate']);
        self::assertSame('42', $response['aggregate_id']);
        self::assertSame('corr-1', $response['entries'][0]['correlation_id']);
    }

    public function test_heartbeat_controller_owns_the_final_live_payload(): void
    {
        [$catalog, $db] = $this->catalog();
        $db->resultSets = [[]];
        $db->values = [2, 3];

        $response = (new HeartbeatController($catalog, $db))->filter([], [
            'tangible_ddd' => ['consumer' => 'test', 'cursor' => 9],
        ]);

        self::assertSame(9, $response['tangible_ddd']['cursor']);
        self::assertSame(['dlq' => 2, 'outbox' => 3], $response['tangible_ddd']['counts']);
    }

    public function test_admin_page_replaces_legacy_callback_and_enqueues_external_assets(): void
    {
        global $_test_actions, $_test_removed_submenus, $_test_enqueued_scripts,
            $_test_enqueued_styles, $_test_inline_scripts;
        [$catalog] = $this->catalog();
        $_test_actions['tools_page_tangible-dddash'][] = 'Tangible\\DDDash\\render_page';
        $page = new AdminPage($catalog, dirname(__DIR__, 3));

        $page->registerMenu();
        $page->enqueue('tools_page_tangible-dddash');

        self::assertSame([['tools.php', 'tangible-dddash']], $_test_removed_submenus);
        self::assertNotContains('Tangible\\DDDash\\render_page', $_test_actions['tools_page_tangible-dddash']);
        self::assertContains([$page, 'render'], $_test_actions['tools_page_tangible-dddash']);
        self::assertArrayHasKey('heartbeat', $_test_enqueued_scripts);
        self::assertArrayHasKey('tangible-dddash', $_test_enqueued_scripts);
        self::assertArrayHasKey('tangible-dddash', $_test_enqueued_styles);
        self::assertSame('before', $_test_inline_scripts['tangible-dddash'][0]['position']);
        self::assertStringContainsString('window.TDDD', $_test_inline_scripts['tangible-dddash'][0]['data']);
    }

    /** @return array{ConsumerCatalog, ScriptedDatabase} */
    private function catalog(): array
    {
        $db = new ScriptedDatabase();
        $db->columns = [[]];
        $handle = new ConsumerHandle(new FakeDDDConfig(), static fn (): object => new \stdClass());
        $catalog = new ConsumerCatalog(
            $db,
            static fn (): array => ['test' => $handle],
            static fn (): Config => new Config('wp_'),
        );
        // Resolve once so ghost discovery consumes its scripted column result
        // before query-specific result queues are configured by each test.
        $catalog->all();
        return [$catalog, $db];
    }
}
