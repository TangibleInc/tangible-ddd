<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * The lease-expiry rule of the relay (CR-PDO-6 ruling, wave 3; was CR
 * sf-8), as an optional capability of an IOutboxStore.
 *
 * Rule, binding on every store that implements this:
 * - claim() of a row that still carries an EXPIRED lease (its previous
 *   holder died without writing an outcome: fatal, OOM, SIGKILL) counts
 *   one relay attempt (`attempts + 1`, `last_error` =
 *   LEASE_EXPIRED_ERROR). Claim::$attempts includes it, so the relay's
 *   retry and dead-letter arithmetic needs no change.
 * - A re-claimed row whose attempts reach its max_attempts is moved to the
 *   DLQ inside claim() (a DLQ entry, status `dlq`) and NOT handed out. It
 *   is then visible wherever dead letters are (IOutboxAdministration::
 *   deadLetters(), the operator view's relay layer).
 *
 * takeDeadLetteredAtClaim() returns the rows claim() dead-lettered since
 * the last call, each with the error stored in the DLQ, and empties the
 * list. The core relay step (OutboxProcessor) calls it after every claim,
 * emits OutboxDeadLettered for each and lists them in
 * ProcessingResult::$deadLetteredAtClaim, so a claim-time dead letter is as
 * visible as a relay-side one.
 *
 * A store without this interface keeps the wave-3 behaviour (only explicit
 * failures count); new stores should implement it.
 */
interface IReportsClaimDeadLetters {

  public const LEASE_EXPIRED_ERROR = 'lease expired without an outcome (submitter crashed or was killed?)';

  /** @return list<array{0: Claim, 1: string}> */
  public function takeDeadLetteredAtClaim(): array;
}
