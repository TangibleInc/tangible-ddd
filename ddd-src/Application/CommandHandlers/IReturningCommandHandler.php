<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;

/**
 * A plain (two-class) command handler that returns a value (D11). The
 * sibling of ICommandHandler, whose `handle(): void` an implementation
 * cannot widen; it is deliberately NOT a subtype of it, so existing `: void`
 * handlers are untouched.
 *
 * Hosts treat it exactly like ICommandHandler: same handler locator, same
 * middleware order. Whatever handle() returns comes back out of the bus and
 * `->send()` unchanged: Correlation, Transaction, Intents and
 * DomainEventsPublish pass it through.
 *
 * The receipt rule applies: return a receipt of scalars or a DTO, never a
 * domain object, and nothing downstream may depend on it. A receipt is
 * computed when handle() returns, BEFORE DomainEventsPublishMiddleware drains
 * the domain events into their in-transaction reactions, so it can never
 * carry what such a reaction creates (an id a reaction inserts, a row a
 * listener writes). Read that back with a query after the command.
 *
 * Backported from the 0.7 line (extraction/ddd-packages, 0af1e11).
 */
interface IReturningCommandHandler {

  public function handle(ICommand $command): mixed;
}
