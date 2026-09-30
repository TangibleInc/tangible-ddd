<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress;

use TangibleDDD\WordPress\Admin\Dashboard\Dashboard;

/** Register the dashboard from the winning framework copy exactly once. */
function register_dashboard(string $frameworkPath): Dashboard
{
    static $dashboard = null;

    if (! $dashboard instanceof Dashboard) {
        $dashboard = Dashboard::forWordPress($frameworkPath);
        $dashboard->register();
    }

    return $dashboard;
}
