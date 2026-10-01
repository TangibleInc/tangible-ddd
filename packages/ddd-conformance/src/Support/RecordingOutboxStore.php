<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use TangibleDDD\Conformance\RelayReport;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/**
 * Pass-through IOutboxStore decorator that turns what a real relay step
 * did into a RelayReport. The core OutboxProcessor returns counts
 * (ProcessingResult); the scenarios assert by event id, so a host hands the
 * processor this decorator around its store and reads report() afterwards.
 *
 * It also forwards IReportsClaimDeadLetters (CR-PDO-6), so the relay step
 * sees the inner store's claim-time dead letters as it would undecorated.
 *
 * Every call goes to the inner store unchanged; only the outcome is noted:
 * claim() → claimed; accept() true → accepted; retryLater() true → retried;
 * deadLetter() true → deadLettered; any fenced write returning false →
 * leaseLost.
 *
 * Caveats for hosts: the decorator is not the inner store, so a transport
 * whose sharesConnectionWith() compares store identity or class must be
 * asked about the inner store (the mem transport ignores its argument).
 * An accept() inside a transaction that later fails to commit is still
 * reported as accepted.
 */
final class RecordingOutboxStore implements IOutboxStore, IReportsClaimDeadLetters {

  /** @var array{claimed: list<string>, accepted: list<string>, retried: list<string>, deadLettered: list<string>, leaseLost: list<string>} */
  private array $seen;

  public function __construct(private readonly IOutboxStore $inner) {
    $this->reset();
  }

  public function inner(): IOutboxStore {
    return $this->inner;
  }

  public function reset(): void {
    $this->seen = ['claimed' => [], 'accepted' => [], 'retried' => [], 'deadLettered' => [], 'leaseLost' => []];
  }

  public function report(): RelayReport {
    return new RelayReport(...$this->seen);
  }

  public function append(OutboxRecord $r): void {
    $this->inner->append($r);
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    $claims = $this->inner->claim($limit, $now, $leaseSeconds);
    foreach ($claims as $c) {
      $this->seen['claimed'][] = $c->event_id;
    }
    return $claims;
  }

  /**
   * CR-PDO-6 rule: pass the inner store's claim-time dead letters to the
   * relay step (which signals and reports them); [] when the inner store
   * does not implement IReportsClaimDeadLetters.
   */
  public function takeDeadLetteredAtClaim(): array {
    return $this->inner instanceof IReportsClaimDeadLetters ? $this->inner->takeDeadLetteredAtClaim() : [];
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    return $this->note($this->inner->accept($c, $transportRef), 'accepted', $c);
  }

  public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->note($this->inner->retryLater($c, $error, $nextAt), 'retried', $c);
  }

  public function deadLetter(Claim $c, string $error): bool {
    return $this->note($this->inner->deadLetter($c, $error), 'deadLettered', $c);
  }

  private function note(bool $matched, string $outcome, Claim $c): bool {
    $this->seen[$matched ? $outcome : 'leaseLost'][] = $c->event_id;
    return $matched;
  }
}
