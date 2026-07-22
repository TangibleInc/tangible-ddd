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
        self::assertStringContainsString('data-view="biography"', $template);
        self::assertStringContainsString('id="tddd-view-biography"', $template);
        self::assertStringContainsString('id="tddd-biography-list"', $template);
        self::assertStringContainsString('id="tddd-biography-detail"', $template);
        self::assertStringContainsString('id="tddd-biography-search"', $template);
        self::assertStringContainsString('id="tddd-drawer-label"', $template);
        self::assertStringContainsString('id="tddd-view-proc"', $template);
        self::assertStringContainsString('id="tddd-view-tables"', $template);
        self::assertStringContainsString('.tddd-root{', $styles);
        self::assertStringContainsString('var R = window.TDDD;', $script);
        self::assertStringContainsString("location.hash='trace/'", $script);
        self::assertStringContainsString('trace-participant', $script);
        self::assertStringContainsString('cross-handoff', $script);
        self::assertStringContainsString('openTraceNode', $script);
        self::assertStringContainsString('trace-biography-link', $script);
        self::assertStringContainsString("setDrawerLabel(n.kind)", $script);
        self::assertStringContainsString("setDrawerLabel('biography entry')", $script);
        self::assertStringContainsString('style="--owner-accent:', $script);
        self::assertStringContainsString('function showBiography', $script);
        self::assertStringContainsString('function biographyHash(consumer,aggregate,aggregateId)', $script);
        self::assertStringContainsString('biographyOwnedMatch', $script);
        self::assertStringContainsString('biographyHash(biographyConsumer,aggregate,aggregateId)', $script);
        self::assertStringContainsString("R.rest+'/biographies?", $script);
        self::assertStringContainsString("R.rest+'/biography?", $script);
        self::assertStringContainsString("return 'biography/", $script);
        self::assertMatchesRegularExpression('/function showTraceRecent\(\)\{\s*startLive\(60\);/', $script);
        self::assertMatchesRegularExpression('/function showTrace\(corr\)\{.*?startLive\(60\);/s', $script);
        self::assertStringContainsString("else if(name==='flow'){ loadFlow(); startLive('fast'); }", $script);
        self::assertStringContainsString('function fmtTraceTime(s)', $script);
        self::assertStringContainsString('d.time_markers', $script);
        self::assertStringContainsString('marker.elapsed_s', $script);
        self::assertStringContainsString('marker.gap_s>=300', $script);
        self::assertStringNotContainsString('if(n.gap_before){', $script);
        self::assertStringContainsString('tl-gap-label', $script);
        self::assertStringContainsString('tl-hiatus', $script);
        self::assertStringContainsString('.tddd-root .tl-gap-label', $styles);
        self::assertStringContainsString(
            '.tddd-root .ruler .rl,.tddd-root .slabel{position:sticky;left:0;z-index:5}',
            $styles,
        );
        self::assertMatchesRegularExpression('/\.tddd-root \.tl-gaps\{[^}]*z-index:4/', $styles);
        self::assertStringContainsString(
            '.tddd-root .tl-gaps{grid-template-columns:250px 1fr}',
            $styles,
        );
        self::assertStringContainsString('biography-entry', $styles);
        self::assertStringContainsString('tabindex="0"', $script);
        self::assertStringContainsString("e.key!=='Enter'&&e.key!==' '", $script);
        self::assertStringContainsString("requested.get('consumer')", $script);
        self::assertStringContainsString("requested.get('correlation')", $script);
        self::assertStringContainsString('--owner-accent', $styles);
        self::assertStringContainsString('.tddd-root .cz{display:flex;min-width:0;flex:1;overflow-x:auto}', $styles);
        self::assertStringContainsString('.tddd-root .trace-legend{flex-wrap:wrap;gap:7px 10px}', $styles);
        self::assertStringContainsString('.tddd-root .ruler{grid-template-columns:250px 1fr}', $styles);
        self::assertStringContainsString('.tddd-root .wf-in-trace .wft-head{flex-wrap:wrap}', $styles);
    }
}
