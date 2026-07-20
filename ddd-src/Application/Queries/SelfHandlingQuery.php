<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Queries;

use TangibleDDD\Application\CQRS\QueryBusAware;

/**
 * A query that carries its own handler — the read-side twin of
 * `SelfHandlingCommand` (spec §14 item 1). Kills the query/handler two-class
 * ceremony while keeping the query bus pipeline intact:
 *
 *   final class FindThing extends SelfHandlingQuery {
 *     public function __construct(private readonly int $thing_id) {}
 *     protected function handle(ThingReadModel $things): ?ThingView {
 *       return $things->find($this->thing_id);
 *     }
 *   }
 *
 *   $view = (new FindThing(42))->send();
 *
 * THE COMMAND/QUERY ASYMMETRY, on purpose: a command's handle() stays VOID
 * by default (the receipt rule — it MAY return a scalar/DTO verdict for
 * transport steering, MUST NOT return domain objects, nothing downstream may
 * depend on it). A query's handle() RETURNS the read result — returning data
 * is what a query IS; there is no receipt rule for reads. The middleware
 * propagates whatever handle() returns straight out of send().
 *
 * Same execution story as the command side, on the QUERY bus: handle()'s
 * parameters are method-injected by reflection from the owning consumer's
 * container by `SelfExecutingCommandMiddleware` (one middleware serves both
 * bases), which is the terminal for these queries — it short-circuits before
 * the naming-convention query-handler resolver. `protected` handle() means
 * the middleware is its only caller. The query bus stays read-shaped: no act
 * bracket, no transaction, no domain-event publishing — a query rides the
 * ambient scope of whoever dispatched it.
 *
 * CONSUMER ROUTING: standalone base, same fix as the command side — no
 * self-consumer container pin. container() falls through to QueryBusAware's
 * registry default (0.2.5c): ConsumerRegistry::owner_of(static::class)
 * ->container(), so a consumer's self-handling query rides its OWN
 * consumer's query bus and resolves handle() deps from its own container.
 *
 * handle() is NOT declared abstract for the same LSP reason as the command
 * base: each concrete query adds its own required, typed dependency params.
 * The base is a pure marker detected via `instanceof SelfHandlingQuery`.
 *
 * The query-handler-as-separate-class path remains fully legal: a plain
 * IQuery NOT extending this base routes to its convention-named handler
 * exactly as before.
 */
abstract class SelfHandlingQuery implements IQuery {

  use QueryBusAware;
}
