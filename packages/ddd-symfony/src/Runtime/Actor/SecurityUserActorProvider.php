<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Actor;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;

/**
 * D5, session user: the authenticated Security user as ActorKind::User, id =
 * getUserIdentifier(), label = the user class. Null when nobody is logged in
 * (or symfony/security-core is absent), so the chain falls through.
 */
final class SecurityUserActorProvider {

  public function __construct(private readonly ?TokenStorageInterface $tokens = null) {}

  public function resolve(): ?Actor {
    $user = $this->tokens?->getToken()?->getUser();
    if ($user === null) {
      return null;
    }
    return new Actor(ActorKind::User, $user->getUserIdentifier(), get_class($user));
  }
}
