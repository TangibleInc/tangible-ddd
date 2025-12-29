<?php
/**
 * Plugin Name: Tangible DDD
 * Plugin URI: https://tangible.one
 * Description: Domain-Driven Design framework for WordPress plugins
 * Version: 0.1.0
 * Author: Tangible
 * Author URI: https://tangible.one
 * License: MIT
 * Requires PHP: 8.1
 *
 * This plugin provides DDD patterns and a WP-CLI scaffolding tool.
 * Consumer plugins should require this via Composer.
 */

namespace TangibleDDD;

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

define( 'TANGIBLE_DDD_VERSION', '0.1.0' );
define( 'TANGIBLE_DDD_PATH', plugin_dir_path( __FILE__ ) );

// Load Composer autoload if available
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
  require_once $autoload;
}

// Register WP-CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
  require_once __DIR__ . '/ddd-wordpress/cli/register.php';
}
