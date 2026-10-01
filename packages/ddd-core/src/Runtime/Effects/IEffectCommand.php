<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

use TangibleDDD\Application\Commands\ICommand;

/**
 * A command with a side effect outside the database (D1), as data (TXP
 * demand E1, wave 5). EffectMiddleware runs every IEffectCommand:
 *
 * - an IExternalEffectCommand performs and records itself (wave 4, for
 *   effects that need no collaborator);
 * - any other IEffectCommand is performed and recorded by its
 *   IExternalEffectHandler, a service with injected dependencies, located
 *   through the bus's handler locator by the naming convention
 *   (Commands\XCommand → CommandHandlers\XHandler).
 *
 * - idempotency_key(): the journal key. The command id is for tracing only.
 * - failure_command(): dispatched ONCE by the core delivery invoker when this
 *   command's subscriber exhausts its handler budget (counted in the
 *   delivery ledger), never from a transport failure event. Null = nothing.
 *   Its command id is uuid5(event_id, "{subscriber}#failure"), so a
 *   re-fired compensation repeats it.
 */
interface IEffectCommand extends ICommand {

  public function idempotency_key(): string;

  public function failure_command(\Throwable $last): ?ICommand;
}
