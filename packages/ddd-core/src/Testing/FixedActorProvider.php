<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\IActorProvider;

/** Always the same actor (System when none is given). */
final class FixedActorProvider implements IActorProvider {

  private readonly Actor $actor;

  public function __construct(?Actor $actor = null) {
    $this->actor = $actor ?? new Actor(ActorKind::System, null);
  }

  public function current(): Actor {
    return $this->actor;
  }
}
