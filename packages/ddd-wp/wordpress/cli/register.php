<?php
/**
 * Register WP-CLI commands for TangibleDDD.
 *
 * @package TangibleDDD
 */

namespace TangibleDDD\WordPress\CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
  return;
}

require_once __DIR__ . '/class-ddd-command.php';

\WP_CLI::add_command( 'ddd', DDD_Command::class );
