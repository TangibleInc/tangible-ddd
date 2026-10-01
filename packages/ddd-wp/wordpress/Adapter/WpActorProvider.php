<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\IActorProvider;

/**
 * The wp IActorProvider (register 3.9, D5), the 0.6
 * CorrelationMiddleware::resolve_source() rule unchanged:
 * CLI SAPI → Cli; WP-Cron (DOING_CRON) → System; a logged-in user → User
 * with the user id; otherwise System. Never throws.
 */
final class WpActorProvider implements IActorProvider {

  public function current(): Actor {
    if (PHP_SAPI === 'cli') {
      return new Actor(ActorKind::Cli, null);
    }
    if (defined('DOING_CRON') && DOING_CRON) {
      return new Actor(ActorKind::System, null);
    }
    $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    return $user_id > 0
      ? new Actor(ActorKind::User, (string) $user_id)
      : new Actor(ActorKind::System, null);
  }
}
