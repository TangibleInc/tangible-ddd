<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * The service that performs and records an IEffectCommand's effect (TXP
 * demand E1, wave 5): `Commands\XCommand` → `CommandHandlers\XHandler`,
 * built by the host container with its collaborators injected (sf:
 * autowired and autoconfigured like an ICommandHandler).
 *
 * - perform(): runs OUTSIDE any transaction (EffectMiddleware refuses an
 *   open one); the result is journaled at once.
 * - record(): runs INSIDE the Transaction middleware's unit of work with the
 *   (possibly journaled) result: load and save aggregates, raise facts.
 *
 * The parameters are typed IEffectCommand because a PHP implementation may
 * not narrow them; an implementation asserts its own command class (the
 * template below is for static analysis).
 *
 * Error behaviour: as IExternalEffectCommand's perform() and record().
 *
 * @template T of IEffectCommand
 */
interface IExternalEffectHandler {

  /** @param T $command */
  public function perform(IEffectCommand $command): EffectResult;

  /** @param T $command */
  public function record(IEffectCommand $command, EffectResult $result): void;
}
