<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Admin;

use TangibleDDD\MegaTrace\Module\ModuleBootstrap;
use TangibleDDD\MegaTrace\Scenario\ScenarioCoordinator;
use TangibleDDD\MegaTrace\Scenario\ScenarioState;

final class AdminPage
{
    public const SLUG = 'tddd-mega-trace';
    private const NONCE = 'tddd_mega_trace_control';

    public function __construct(
        private readonly ScenarioCoordinator $coordinator,
        private readonly ScenarioState $state,
        private readonly ModuleBootstrap $modules,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_tddd_mega_trace_start', [$this, 'start']);
        add_action('admin_post_tddd_mega_trace_toggle', [$this, 'toggle']);
    }

    public function menu(): void
    {
        add_management_page(
            'DDD Mega Trace',
            'DDD Mega Trace',
            'manage_options',
            self::SLUG,
            [$this, 'render'],
        );
    }

    public function start(): void
    {
        $this->guard();
        $this->guard_ready();
        $this->coordinator->start();
        $this->redirect();
    }

    public function toggle(): void
    {
        $this->guard();
        if (isset($_POST['enabled'])) {
            $this->guard_ready();
            $this->coordinator->enable();
        } else {
            $this->coordinator->disable();
        }
        $this->redirect();
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $enabled = $this->state->enabled();
        $run = $this->state->last_run();
        $missing = $this->modules->missing_hosts();
        ?>
        <div class="wrap">
            <h1>DDD Mega Trace</h1>
            <?php if ($missing !== []): ?>
                <div class="notice notice-error inline"><p>
                    Missing consumers: <code><?php echo esc_html(implode(', ', $missing)); ?></code>
                </p></div>
            <?php endif; ?>
            <div style="margin:12px 0">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
                    <input type="hidden" name="action" value="tddd_mega_trace_start">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <button class="button button-primary" <?php disabled($missing !== []); ?>>Start scenario</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                    <input type="hidden" name="action" value="tddd_mega_trace_toggle">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <label>
                        <input type="checkbox" name="enabled" value="1" onchange="this.form.submit()" <?php checked($enabled); ?>>
                        Automatic runs
                    </label>
                </form>
            </div>
            <?php if ($run !== null): ?>
                <table class="widefat striped" style="max-width:760px">
                    <tbody>
                        <tr><th>Started</th><td><?php echo esc_html(wp_date('Y-m-d H:i:s', $run->started_at)); ?></td></tr>
                        <tr><th>Scenario</th><td><code><?php echo esc_html($run->scenario_id); ?></code></td></tr>
                        <tr><th>Correlation</th><td><code><?php echo esc_html($run->correlation_id); ?></code></td></tr>
                        <?php // The dashboard routes by HASH (#trace/<correlation>); it reads no
      // consumer/correlation query params — the old href left inert params
      // stuck in the address bar while never actually opening the trace. ?>
                        <tr><th>Trace</th><td><a class="button" href="<?php echo esc_url(admin_url('tools.php?page=tangible-dddash') . '#trace/' . rawurlencode($run->correlation_id)); ?>">Open trace</a></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }
        check_admin_referer(self::NONCE);
    }

    private function guard_ready(): void
    {
        $missing = $this->modules->missing_hosts();
        if ($missing !== []) {
            wp_die('Missing DDD consumers: ' . esc_html(implode(', ', $missing)));
        }
    }

    private function redirect(): void
    {
        wp_safe_redirect(admin_url('tools.php?page=' . self::SLUG));
        exit;
    }
}
