<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Actor;

use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\SapiActorProvider;

/**
 * The bundle's IActorProvider (register 3.9, D5), first match wins:
 * 1. ActorContext (an explicitly set actor, e.g. a machine authenticator);
 * 2. the authenticated Security user (ActorKind::User);
 * 3. the console operator of the running command (ActorKind::Cli, or
 *    System for unattended workers);
 * 4. SapiActorProvider (Cli/System by SAPI).
 * Never throws: a failing source is skipped.
 */
final class SymfonyActorProvider implements IActorProvider {

  public function __construct(
    private readonly ActorContext $context,
    private readonly SecurityUserActorProvider $security,
    private readonly ConsoleOperatorActorProvider $console,
    private readonly IActorProvider $fallback = new SapiActorProvider(),
  ) {}

  public function current(): Actor {
    foreach ([fn () => $this->context->get(), fn () => $this->security->resolve(), fn () => $this->console->resolve()] as $source) {
      try {
        $actor = $source();
      } catch (\Throwable) {
        $actor = null;
      }
      if ($actor !== null) {
        return $actor;
      }
    }
    return $this->fallback->current();
  }
}
