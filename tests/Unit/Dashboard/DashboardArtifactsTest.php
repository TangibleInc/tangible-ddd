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
use TangibleDDD\WordPress\Admin\Dashboard\Dashboard;
use TangibleDDD\WordPress\Admin\Dashboard\HeartbeatController;
use TangibleDDD\WordPress\Admin\Dashboard\RestController;

final class DashboardArtifactsTest extends TestCase
{
    public function test_composition_root_registers_rest_heartbeat_and_admin_hooks(): void
    {
        global $_test_actions, $_test_filters;
        $_test_actions = [];
        $_test_filters = [];
        $db = new ScriptedDatabase();
        $db->columns = [[]];
        $handle = new ConsumerHandle(new FakeDDDConfig(), static fn (): object => new \stdClass());
        $catalog = new ConsumerCatalog(
            $db,
            static fn (): array => ['test' => $handle],
            static fn (): Config => new Config('wp_'),
        );
        $dashboard = new Dashboard(
            new RestController($catalog, $db, new ActionDispatcher(static fn (): null => null)),
            new HeartbeatController($catalog, $db),
            new AdminPage($catalog, dirname(__DIR__, 3)),
        );

        $dashboard->register();

        self::assertNotEmpty($_test_actions['rest_api_init'] ?? []);
        self::assertNotEmpty($_test_actions['admin_menu'] ?? []);
        self::assertNotEmpty($_test_actions['admin_enqueue_scripts'] ?? []);
        self::assertNotEmpty($_test_filters['heartbeat_received'] ?? []);
        self::assertNotEmpty($_test_filters['admin_body_class'] ?? []);
    }

    public function test_reference_interface_is_split_without_inline_runtime_code(): void
    {
        $root = dirname(__DIR__, 3) . '/ddd-wordpress/Admin/Dashboard';
        $template = file_get_contents($root . '/template.php');
        $styles = file_get_contents($root . '/assets/dashboard.css');
        $script = file_get_contents($root . '/assets/dashboard.js');

        self::assertStringStartsWith('    <div class="tddd-root"', $template);
        self::assertStringNotContainsString('print_styles', $template);
        self::assertStringNotContainsString('<style', $template);
        self::assertStringNotContainsString('<script', $template);
        self::assertStringContainsString('id="tddd-view-flow"', $template);
        self::assertStringContainsString('id="tddd-view-audit"', $template);
        self::assertStringContainsString('id="tddd-view-trace"', $template);
        self::assertStringContainsString('id="tddd-view-proc"', $template);
        self::assertStringContainsString('id="tddd-view-tables"', $template);
        self::assertStringContainsString('.tddd-root{', $styles);
        self::assertStringContainsString('var R = window.TDDD;', $script);
        self::assertStringContainsString("location.hash='trace/'", $script);
        self::assertStringContainsString('trace-participant', $script);
        self::assertStringContainsString('cross-handoff', $script);
        self::assertStringContainsString('openTraceNode', $script);
        self::assertStringContainsString('--owner-accent', $styles);
    }
}
