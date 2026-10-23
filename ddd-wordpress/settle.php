<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\Services\DueWorkSettler;
use TangibleDDD\Infra\Services\SettleResult;

/**
 * Settle the async work already due across every registered consumer:
 * relay their outbox rows and run their due Action Scheduler actions,
 * pass after pass, until nothing is left. See DueWorkSettler.
 *
 * Read after init:2, like consumers(): a consumer that has not booted yet
 * is not settled.
 */
function settle(int $max_passes = DueWorkSettler::DEFAULT_MAX_PASSES): SettleResult {
  return (new DueWorkSettler(consumers()))->settle($max_passes);
}

/**
 * `POST /tangible-ddd/v1/settle`, for administrators. End-to-end tests call
 * it instead of polling WP-Cron until a reaction lands. Optional body:
 * `{ "max_passes": <int> }`.
 */
function register_settle_route(): void {
  static $registered = false;
  if( $registered ) {
    return;
  }
  $registered = true;

  add_action('rest_api_init', static function(): void {
    register_rest_route('tangible-ddd/v1', '/settle', [
      'methods' => 'POST',
      'permission_callback' => static fn(): bool => current_user_can('manage_options'),
      'args' => [
        'max_passes' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => 200,
          'default' => DueWorkSettler::DEFAULT_MAX_PASSES,
        ],
      ],
      'callback' => static fn(\WP_REST_Request $request): array => settle((int) $request->get_param('max_passes'))->to_array(),
    ]);
  });
}
