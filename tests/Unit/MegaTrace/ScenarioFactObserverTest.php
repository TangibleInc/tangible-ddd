<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\MegaTrace\Module\ModuleManifest;
use TangibleDDD\MegaTrace\Module\ScenarioFactObserver;

final class ScenarioFactObserverTest extends TestCase
{
    protected function setUp(): void
    {
        global $_test_actions;
        $_test_actions = [];
        ConsumerRegistry::reset();

        foreach (ModuleManifest::definitions() as $module) {
            $host_root = substr($module->namespace_root, 0, strrpos($module->namespace_root, '\\'));
            ConsumerRegistry::add(
                new DDDConfig($module->host_prefix, $host_root, 'test'),
                static fn (): object => new \stdClass(),
            );
            ConsumerRegistry::add_module(
                $module->host_prefix,
                $module->namespace_root,
                static fn (): object => new \stdClass(),
            );
        }
    }

    protected function tearDown(): void
    {
        ConsumerRegistry::reset();
    }

    public function test_every_scenario_fact_has_a_transport_callback(): void
    {
        global $_test_actions;
        $observer = new ScenarioFactObserver();

        foreach (ModuleManifest::definitions() as $module) {
            $observer->register($module);
            foreach ($module->events as $event_class) {
                self::assertNotEmpty(
                    $_test_actions[$event_class::integration_action()] ?? [],
                    $event_class . ' would fail as an Action Scheduler job without a callback',
                );
            }
        }
    }
}
