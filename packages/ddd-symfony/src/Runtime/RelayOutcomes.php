<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Log\LoggerInterface;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;

/**
 * The store as one core relay step sees it: every call goes to the real
 * store, and the outcome of each fenced write is recorded per event id, so
 * Relay can return a RelayReport by id (the core step returns counts).
 *
 * On a shared connection a lost lease on accept throws LeaseLostOnAccept,
 * inside the core step's boundary transaction, so the submission rolls back
 * with it (CR sf-3).
 *
 * One instance serves one step.
 *
 * @internal
 */
final class RelayOutcomes implements IOutboxStore {

  /** @var list<string> */
  private array $claimed = [];

  /** @var array<string, 'accepted'|'retried'|'dlq'|'lost'> */
  private array $outcome = [];

  /** @var list<array{0: Claim, 1: string}> */
  private array $claimDeadLetters = [];

  public function __construct(
    private readonly IOutboxStore $inner,
    private readonly bool $shared,
    private readonly LoggerInterface $logger,
  ) {}

  public function append(OutboxRecord $r): void {
    $this->inner->append($r);
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    $claims = $this->inner->claim($limit, $now, $leaseSeconds);
    if ($this->inner instanceof DbalPostgresOutboxStore) {
      $this->claimDeadLetters = [...$this->claimDeadLetters, ...$this->inner->takeDeadLetteredAtClaim()];
    }
    foreach ($claims as $c) {
      $this->claimed[] = $c->event_id;
    }
    return $claims;
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    if ($this->inner->accept($c, $transportRef)) {
      $this->outcome[$c->event_id] = 'accepted';
      return true;
    }
    $this->outcome[$c->event_id] = 'lost';
    if ($this->shared) {
      $this->logger->warning("[ddd relay] lease lost on accept of {$c->event_id}; the submission rolls back with it");
      throw new LeaseLostOnAccept($c->event_id);
    }
    return false;
  }

  public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->record($c, $this->inner->retryLater($c, $error, $nextAt), 'retried');
  }

  public function deadLetter(Claim $c, string $error): bool {
    return $this->record($c, $this->inner->deadLetter($c, $error), 'dlq');
  }

  /** @return list<array{0: Claim, 1: string}> rows the store dead-lettered at claim time, with the error */
  public function claimDeadLetters(): array {
    return $this->claimDeadLetters;
  }

  public function report(): RelayReport {
    $by = ['accepted' => [], 'retried' => [], 'dlq' => [], 'lost' => []];
    foreach ($this->claimed as $id) {
      if (isset($this->outcome[$id])) {
        $by[$this->outcome[$id]][] = $id;
      }
    }
    $atClaim = array_map(static fn (array $l) => $l[0]->event_id, $this->claimDeadLetters);
    return new RelayReport($this->claimed, $by['accepted'], $by['retried'], [...$atClaim, ...$by['dlq']], $by['lost']);
  }

  private function record(Claim $c, bool $ok, string $outcome): bool {
    // A follow-up write after a lost accept is fenced too: it stays `lost`.
    if (($this->outcome[$c->event_id] ?? null) !== 'lost') {
      $this->outcome[$c->event_id] = $ok ? $outcome : 'lost';
    }
    return $ok;
  }
}
