<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

final class AdminPage
{
    public const SLUG = 'tangible-dddash';
    public const HOOK = 'tools_page_' . self::SLUG;

    public function __construct(
        private readonly ConsumerCatalog $consumers,
        private readonly string $frameworkPath,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu'], 100);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('admin_body_class', [$this, 'bodyClass']);
    }

    public function registerMenu(): void
    {
        // The historical mu-plugin loads first. Remove its page hook and menu
        // entry before claiming the same public slug with framework code.
        remove_action(self::HOOK, 'Tangible\\DDDash\\render_page');
        remove_submenu_page('tools.php', self::SLUG);
        add_management_page(
            'TangibleDDDash',
            'TangibleDDDash',
            'manage_options',
            self::SLUG,
            [$this, 'render'],
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== self::HOOK) {
            return;
        }

        $base = 'ddd-wordpress/Admin/Dashboard/assets/';
        $pluginFile = $this->frameworkPath . '/tangible-ddd.php';
        $version = defined('TANGIBLE_DDD_VERSION') ? (string) TANGIBLE_DDD_VERSION : 'dev';

        wp_enqueue_script('heartbeat');
        wp_enqueue_style(
            'tangible-dddash',
            plugins_url($base . 'dashboard.css', $pluginFile),
            [],
            $version,
        );
        // Vendored UMD runtimes for the trace island (classic scripts, no build).
        wp_enqueue_script(
            'tangible-dddash-preact',
            plugins_url($base . 'vendor/preact.min.js', $pluginFile),
            [],
            $version,
            true,
        );
        wp_enqueue_script(
            'tangible-dddash-preact-hooks',
            plugins_url($base . 'vendor/preact-hooks.umd.js', $pluginFile),
            ['tangible-dddash-preact'],
            $version,
            true,
        );
        wp_enqueue_script(
            'tangible-dddash-htm',
            plugins_url($base . 'vendor/htm.js', $pluginFile),
            [],
            $version,
            true,
        );
        wp_enqueue_script(
            'tangible-dddash-trace',
            plugins_url($base . 'trace-island.js', $pluginFile),
            ['tangible-dddash-preact', 'tangible-dddash-preact-hooks', 'tangible-dddash-htm'],
            $version,
            true,
        );
        wp_enqueue_script(
            'tangible-dddash',
            plugins_url($base . 'dashboard.js', $pluginFile),
            ['jquery', 'heartbeat', 'tangible-dddash-trace'],
            $version,
            true,
        );

        $boot = [
            'rest' => esc_url_raw(rest_url(RestController::NAMESPACE)),
            'nonce' => wp_create_nonce('wp_rest'),
            'consumers' => $this->labels(),
        ];
        wp_add_inline_script(
            'tangible-dddash',
            'window.TDDD = ' . wp_json_encode($boot) . ';',
            'before',
        );
    }

    public function bodyClass(string $classes): string
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === self::HOOK) {
            $classes .= ' folded tddd-fullbleed';
        }
        return $classes;
    }

    public function render(): void
    {
        require $this->frameworkPath . '/ddd-wordpress/Admin/Dashboard/template.php';
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        $labels = [];
        foreach ($this->consumers->all() as $key => $consumer) {
            $labels[$key] = $consumer->label . ($consumer->ghost ? ' ' . "\u{1F47B}" : '');
        }
        return $labels;
    }
}
