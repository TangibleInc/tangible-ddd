<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/** Default IActorProvider: Cli under the CLI SAPI, System otherwise. */
final class SapiActorProvider implements IActorProvider {

  public function current(): Actor {
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg'
      ? new Actor(ActorKind::Cli, null)
      : new Actor(ActorKind::System, null);
  }
}
