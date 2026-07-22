<?php
/**
 * Plugin Name: Tangible DDD Mega Trace
 * Description: Timed cross-consumer scenario for TangibleDDDash development.
 * Version: 0.1.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

(new \TangibleDDD\MegaTrace\Plugin())->register(__FILE__);
