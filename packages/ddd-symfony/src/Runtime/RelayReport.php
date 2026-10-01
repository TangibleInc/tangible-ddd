<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Infra\Services\ProcessingResult;

/** What one relay step did, by event id. */
final class RelayReport {

  /**
   * @param list<string> $claimed
   * @param list<string> $accepted
   * @param list<string> $retried
   * @param list<string> $dead_lettered relay-side dead letters plus rows the store dead-lettered at claim (CR sf-8)
   * @param list<string> $lost lease lost on the follow-up write; result discarded
   * @param ?ProcessingResult $result the core step's own result (process_batch($limit), CR sfc-3, sfc-4)
   */
  public function __construct(
    public readonly array $claimed = [],
    public readonly array $accepted = [],
    public readonly array $retried = [],
    public readonly array $dead_lettered = [],
    public readonly array $lost = [],
    public readonly ?ProcessingResult $result = null,
  ) {}

  public static function of(ProcessingResult $r, array $deadLetteredAtClaim = []): self {
    return new self($r->claimed, $r->accepted, $r->retried, [...$deadLetteredAtClaim, ...$r->dead_lettered], $r->lease_lost, $r);
  }
}
