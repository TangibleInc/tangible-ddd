<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Commands;

use TangibleDDD\Application\CQRS\CommandBusAware;

/**
 * A command that carries its own handler (spec §14 item 1; name ruled over
 * `SelfContainedCommand`, the `IHandlesItself` working title retired).
 *
 * Kills the command/handler two-class ceremony while keeping the middleware
 * onion intact. A concrete self-handling command declares:
 *
 *   final class RecordThing extends SelfHandlingCommand {
 *     public function __construct(private readonly int $thing_id) {}
 *     protected function handle(ThingRepository $repo): void {
 *       $repo->touch($this->thing_id);
 *     }
 *   }
 *
 *   (new RecordThing(42))->send();
 *
 * The handle() parameters are method-injected by reflection from the owning
 * consumer's container (like Symfony's ArgumentResolver — Symfony has no
 * `container->call()`, so `SelfExecutingCommandMiddleware` does the
 * reflect+resolve itself). `protected` + PHP 8.1's `setAccessible` no-op
 * means only that middleware can invoke handle() — there is no manual call
 * site. The middleware short-circuits the naming-convention handler resolver
 * for these commands, so no separate handler class is looked up.
 *
 * CONSUMER ROUTING: this base is STANDALONE — it deliberately does NOT
 * extend the framework's self-consumer `Command` base, whose container()
 * override pins `TangibleDDD\WordPress\SelfConsumer\di()`. That pin would
 * send a CONSUMER's self-handling command through the FRAMEWORK's bus and
 * resolve its handle() deps from a container where consumer services do not
 * exist. Instead, container() falls through to CommandBusAware's registry
 * default (0.2.5c): `ConsumerRegistry::owner_of(static::class)->container()`
 * — the concrete command's namespace names its consumer, ->send() rides that
 * consumer's bus, and its handle() deps resolve from that consumer's
 * container. The framework's OWN commands may extend this base only because
 * the framework registers itself as a consumer on the `TangibleDDD` root.
 *
 * handle() stays VOID by default (the receipt rule, spec §14 item 2): it MAY
 * return a scalar/DTO verdict for transport steering, MUST NOT return domain
 * objects, and nothing downstream may depend on the return — the middleware
 * merely propagates whatever it returns.
 *
 * WHY handle() IS NOT DECLARED ABSTRACT HERE: each concrete command adds its
 * own required, typed dependency parameters to handle(). An abstract method
 * with a fixed signature would make those additions LSP violations (a
 * subclass may not add required parameters to an inherited abstract method).
 * The base is therefore a pure marker the middleware detects via
 * `instanceof SelfHandlingCommand`; it declares no handle() signature and
 * gets send()/container() from CommandBusAware.
 *
 * The handler-as-separate-class path remains fully legal (and preferable for
 * dependency-heavy handlers): a plain command NOT extending this base routes
 * to its convention-named handler exactly as before.
 */
abstract class SelfHandlingCommand implements ICommand {

  use CommandBusAware;
}
