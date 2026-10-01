<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Conformance\RelayReport;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/**
 * Pass-through IOutboxStore around the wpdb store that the relay step is
 * given, recording what one OutboxProcessor::process_batch() did by event
 * id, so HostFixture::relayOnce() can answer a RelayReport (the core relay
 * returns counts only). Changes no behaviour.
 */
final class RecordingOutboxStore implements IOutboxStore {

  /** @var array{claimed: list<string>, accepted: list<string>, retried: list<string>, deadLettered: list<string>, leaseLost: list<string>} */
  private array $seen;

  public function __construct(public readonly IOutboxStore $inner) {
    $this->start();
  }

  public function start(): void {
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

  public function accept(Claim $c, ?string $transportRef): bool {
    return $this->note($this->inner->accept($c, $transportRef), 'accepted', $c);
  }

  public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->note($this->inner->retryLater($c, $error, $nextAt), 'retried', $c);
  }

  public function deadLetter(Claim $c, string $error): bool {
    return $this->note($this->inner->deadLetter($c, $error), 'deadLettered', $c);
  }

  private function note(bool $matched, string $what, Claim $c): bool {
    $this->seen[$matched ? $what : 'leaseLost'][] = $c->event_id;
    return $matched;
  }
}
