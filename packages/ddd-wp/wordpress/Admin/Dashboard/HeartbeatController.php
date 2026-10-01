<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

use TangibleDDD\WordPress\Admin\Dashboard\Query\LiveQuery;

final class HeartbeatController
{
    public function __construct(
        private readonly ConsumerCatalog $consumers,
        private readonly Database $db,
    ) {
    }

    public function register(): void
    {
        // Later than the reference mu-plugin's priority 10 filter: this
        // implementation owns the final payload when both files are present.
        add_filter('heartbeat_received', [$this, 'filter'], 20, 2);
    }

    public function filter(mixed $response, mixed $data): mixed
    {
        if (! is_array($response)) {
            $response = [];
        }
        if (
            ! is_array($data)
            || empty($data['tangible_ddd'])
            || ! current_user_can('manage_options')
        ) {
            return $response;
        }

        $request = (array) $data['tangible_ddd'];
        $consumer = preg_replace('/[^a-z_]/', '', (string) ($request['consumer'] ?? 'datastream')) ?? '';
        $config = $this->consumers->config($consumer);
        if ($config !== null) {
            $response['tangible_ddd'] = (new LiveQuery($config, $this->db))->tick(
                (int) ($request['cursor'] ?? 0),
            );
        }
        return $response;
    }
}
