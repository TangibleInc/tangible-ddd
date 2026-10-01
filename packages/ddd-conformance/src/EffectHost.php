<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use League\Tactician\CommandBus;
use TangibleDDD\Runtime\Effects\IEffectJournal;

/**
 * Optional seam for `effect.journal-reuse` (D1; wave 4, CR-W4C4-2;
 * register 3.8 D1, 5.1). A separate interface, so HostFixture is
 * unchanged; a fixture without it has the scenario skipped with the
 * request id.
 *
 * - effect_journal(): the host's IEffectJournal, on the host connection,
 *   so an invalidate() inside a command's transaction rolls back with it
 *   (sf DbalEffectJournal, pdo, mem InMemoryEffectJournal enlisted in the
 *   boundary).
 * - effect_bus($handlers): the host command bus with core EffectMiddleware
 *   in its frozen place, act bracket → Effect → Transaction →
 *   DomainEventsPublish → handler, over effect_journal() and
 *   HostFixture::boundary(). RecordEffect reaches RecordEffect::apply()
 *   (the host's SelfExecuting stage, or a handler-map entry the host adds);
 *   $handlers serves every other command, as in HostFixture::command_bus().
 *
 * The scenario registers its translator with the core SubscriptionRegistrar
 * on HostFixture::subscriptions() and delivers through HostFixture::deliver(),
 * so the budget is the host delivery runner's ledger budget and the failure
 * command is fired by the core invoker (on_exhausted), never by the host.
 */
interface EffectHost {

  public function effect_journal(): IEffectJournal;

  /** @param array<class-string, callable(object): mixed> $handlers */
  public function effect_bus(array $handlers): CommandBus;
}
