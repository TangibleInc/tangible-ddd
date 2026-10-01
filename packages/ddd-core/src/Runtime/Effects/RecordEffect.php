<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;

/**
 * The inner message EffectMiddleware sends down the onion (D1): "record
 * this effect's result". It is an ITransactionalCommand, so record() always
 * runs inside the Transaction middleware's unit of work, and a
 * SelfHandlingCommand, so SelfExecutingCommandMiddleware runs it with no
 * handler class. A host whose terminal is a handler map routes it to
 * apply().
 *
 * Never sent by hand: send() throws. The act bracket sits outside
 * EffectMiddleware, so the audit row and the command id are the effect
 * command's own.
 */
final class RecordEffect extends SelfHandlingCommand implements ITransactionalCommand {

  public function __construct(
    public readonly IExternalEffectCommand $effect,
    public readonly EffectResult $result,
  ) {}

  /** Calls record() with the (possibly journaled) result and returns it. */
  public function apply(): EffectResult {
    $this->effect->record($this->result);
    return $this->result;
  }

  public function send(): mixed {
    throw new \LogicException('RecordEffect is dispatched by EffectMiddleware only; send the IExternalEffectCommand itself.');
  }

  protected function handle(): EffectResult {
    // A self-handling effect command gets the act-level event lane too.
    $uow = $this->events_uow();
    if ($uow !== null && $this->effect instanceof SelfHandlingCommand) {
      $this->effect->attach_events_uow($uow);
    }
    return $this->apply();
  }
}
