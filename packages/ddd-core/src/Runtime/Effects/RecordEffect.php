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
 * Wave 5: $effect is any IEffectCommand. With $handler (E1) the handler's
 * record() runs, otherwise the IExternalEffectCommand's own. With $journal
 * (E2) apply() marks the entry recorded after record() returned, inside the
 * same transaction.
 *
 * Never sent by hand: send() throws. The act bracket sits outside
 * EffectMiddleware, so the audit row and the command id are the effect
 * command's own.
 */
final class RecordEffect extends SelfHandlingCommand implements ITransactionalCommand {

  public function __construct(
    public readonly IEffectCommand $effect,
    public readonly EffectResult $result,
    public readonly ?IExternalEffectHandler $handler = null,
    public readonly ?ITracksEffectState $journal = null,
  ) {
    if ($handler === null && !$effect instanceof IExternalEffectCommand) {
      throw new \InvalidArgumentException(get_class($effect) . ' records through an IExternalEffectHandler; none was given');
    }
  }

  /** Calls record() with the (possibly journaled) result, marks it recorded, and returns it. */
  public function apply(): EffectResult {
    if ($this->handler !== null) {
      $this->handler->record($this->effect, $this->result);
    } else {
      \assert($this->effect instanceof IExternalEffectCommand);
      $this->effect->record($this->result);
    }
    $this->journal?->mark_recorded($this->effect->idempotency_key());
    return $this->result;
  }

  public function send(): mixed {
    throw new \LogicException('RecordEffect is dispatched by EffectMiddleware only; send the effect command itself.');
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
