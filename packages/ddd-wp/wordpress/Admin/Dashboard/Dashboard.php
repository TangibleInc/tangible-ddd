<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

final class Dashboard
{
    public function __construct(
        private readonly RestController $rest,
        private readonly HeartbeatController $heartbeat,
        private readonly AdminPage $page,
    ) {
    }

    public static function forWordPress(string $frameworkPath): self
    {
        $database = WpDatabase::fromGlobal();
        $consumers = new ConsumerCatalog($database);

        return new self(
            new RestController($consumers, $database, new ActionDispatcher()),
            new HeartbeatController($consumers, $database),
            new AdminPage($consumers, $frameworkPath),
        );
    }

    public function register(): void
    {
        // The mu-plugin reference registers at the default priority. Register
        // later and use REST route override so this composition owns v1.
        add_action('rest_api_init', [$this->rest, 'registerRoutes'], 100);
        $this->heartbeat->register();
        $this->page->register();
    }
}
