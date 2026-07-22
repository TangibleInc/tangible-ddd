<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace;

use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\MegaTrace\Admin\AdminPage;
use TangibleDDD\MegaTrace\Infra\WordPressScenarioQueue;
use TangibleDDD\MegaTrace\Infra\WordPressScenarioState;
use TangibleDDD\MegaTrace\Module\ModuleBootstrap;
use TangibleDDD\MegaTrace\Module\ModuleContainerFactory;
use TangibleDDD\MegaTrace\Scenario\ScenarioCoordinator;
use TangibleDDD\MegaTrace\Scenario\ScenarioConductor;
use TangibleDDD\MegaTrace\Scenario\ScenarioPlan;
use TangibleDDD\MegaTrace\Scenario\ScenarioRuntime;

final class Plugin
{
    private readonly WordPressScenarioState $state;
    private readonly ScenarioRuntime $runtime;
    private readonly ScenarioCoordinator $coordinator;
    private readonly ScenarioConductor $conductor;
    private readonly ModuleBootstrap $modules;
    private readonly AdminPage $admin;

    public function __construct()
    {
        $this->state = new WordPressScenarioState();
        $this->runtime = new ScenarioRuntime();
        $queue = new WordPressScenarioQueue();
        $now = static fn (): int => time();
        $this->coordinator = new ScenarioCoordinator(
            $queue,
            $this->state,
            $this->runtime,
            $now,
            static fn (): string => Uuid::v4(),
        );
        $this->conductor = new ScenarioConductor($queue, $now);
        $this->modules = new ModuleBootstrap(new ModuleContainerFactory());
        $this->admin = new AdminPage($this->coordinator, $this->state, $this->modules);
    }

    public function register(string $plugin_file): void
    {
        add_action('plugins_loaded', [$this->modules, 'register'], 30);
        add_action('plugins_loaded', [$this->conductor, 'register'], 31);
        add_action(ScenarioCoordinator::SPAWN_HOOK, [$this, 'spawn']);
        add_action(ScenarioPlan::SUBMIT_DIAGNOSTIC, [$this->runtime, 'submit_diagnostic'], 10, 2);
        add_action(ScenarioPlan::SUBMIT_CAPSTONE, [$this->runtime, 'submit_capstone'], 10, 2);
        add_action(ScenarioPlan::RECORD_ATTESTATION, [$this->runtime, 'record_attestation'], 10, 2);
        add_action(ScenarioPlan::ACKNOWLEDGE_REGISTRY, [$this->runtime, 'acknowledge_registry'], 10, 2);
        add_action('tgbl_cred_mega_trace_workflow_continue', [$this->runtime, 'continue_workflow'], 10, 5);
        $this->admin->register();

        register_deactivation_hook($plugin_file, [$this->coordinator, 'disable']);
    }

    public function spawn(): void
    {
        if (!$this->state->enabled()) {
            return;
        }

        try {
            $this->coordinator->start();
        } catch (\Throwable $error) {
            error_log('[ddd-mega-trace] ' . $error->getMessage());
            throw $error;
        }
    }
}
