<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

use League\Tactician\Middleware;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\ITransactionBoundary;

/**
 * D1 ExternalEffect (register 3.8, 3.11): the command-bus stage for
 * IExternalEffectCommand. Sits between Correlation and Transaction:
 *
 *   Correlation → Effect → Transaction → DomainEventsPublish →
 *   SelfExecuting → handler
 *
 * For an IExternalEffectCommand:
 *
 *   1. key = idempotency_key(); journal->find(key).
 *   2. No entry: perform() runs OUTSIDE any transaction (inside the act
 *      scope, so it is audited and traced as the command), and its result
 *      is stored in the journal at once, in its own autocommit. The entry
 *      therefore survives a later rollback of record().
 *   3. The rest of the onion receives RecordEffect(command, result), an
 *      ITransactionalCommand: the Transaction middleware opens the unit of
 *      work, domain events recorded by record() publish inside it, and
 *      the terminal (SelfExecutingCommandMiddleware) calls record().
 *   4. The bus returns the EffectResult (D11).
 *
 * A retry (a redelivery of the fact, a process-level step retry, a second
 * dispatch under a new command id) finds the entry and goes straight to
 * record() with the journaled result: perform() is not called again. The
 * only way to perform again is the explicit repair path,
 * IEffectJournal::invalidate(key, reason), run inside the repair command's
 * own transaction before the effect is re-dispatched.
 *
 * The retry budget is NOT counted here. A fact-triggered effect is retried
 * by the delivery runner; its failures are counted per subscriber in the
 * delivery ledger, and when the budget is reached IntegrationDelivery fires
 * the subscriber's on_exhausted, which SubscriptionRegistrar wires to
 * failure_command() (register 5.1; never from a transport failure event).
 * Inside a process step, the step's retry policy governs.
 *
 * Concurrency: two workers performing the same key at the same moment both
 * call perform(); the command passes its idempotency_key() to the external
 * system (e.g. Stripe's Idempotency-Key) so the second call is absorbed
 * there. The journal records the last stored result.
 *
 * Error behaviour:
 * - NoEffectJournal (\LogicException) before perform() when no journal is
 *   injected or provided through HostDefaults.
 * - EffectInsideTransaction (\LogicException) before perform() when the
 *   boundary reports an open transaction.
 * - \InvalidArgumentException for an empty idempotency key.
 * - perform()'s exception propagates with nothing journaled; record()'s
 *   propagates after the Transaction middleware rolled back, with the
 *   journal entry kept. Journal storage errors propagate.
 *
 * Other commands pass through untouched. Hosts without a SelfExecuting
 * stage must route RecordEffect to RecordEffect::apply().
 */
final class EffectMiddleware implements Middleware {

  public function __construct(
    private readonly ?IEffectJournal $journal = null,
    private readonly ?ITransactionBoundary $boundary = null,
  ) {}

  public function execute($command, callable $next) {
    if (!$command instanceof IExternalEffectCommand) {
      return $next($command);
    }

    $journal = $this->journal ?? HostDefaults::get(IEffectJournal::class);
    if (!$journal instanceof IEffectJournal) {
      throw new NoEffectJournal(sprintf(
        '%s is an IExternalEffectCommand but no IEffectJournal is configured (constructor or HostDefaults).',
        get_class($command)
      ));
    }

    $key = $command->idempotency_key();
    if ($key === '') {
      throw new \InvalidArgumentException(get_class($command) . '::idempotencyKey() returned an empty key');
    }

    $result = $journal->find($key);
    if ($result === null) {
      if ($this->boundary()?->is_active()) {
        throw new EffectInsideTransaction(sprintf(
          '%s would perform its external effect inside an open transaction; dispatch it outside one.',
          get_class($command)
        ));
      }
      $result = $command->perform();
      $journal->store($key, $result);
    }

    return $next(new RecordEffect($command, $result));
  }

  private function boundary(): ?ITransactionBoundary {
    return $this->boundary ?? HostDefaults::get(ITransactionBoundary::class);
  }
}
