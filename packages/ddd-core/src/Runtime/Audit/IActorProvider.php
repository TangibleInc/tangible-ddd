<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * The current actor for the audit row (register 3.9, D5). wp: the WP user;
 * sf: the session user or console operator (TXP authenticators set Machine).
 *
 * Error behaviour: never throws; falls back to Cli/System by SAPI
 * (SapiActorProvider) when nothing better is known.
 * Lifetime: read per command, at bracket open.
 */
interface IActorProvider {
  public function current(): Actor;
}
