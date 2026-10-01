<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

/** What one relay step did, by event id. */
final class RelayReport {

  /**
   * @param list<string> $claimed
   * @param list<string> $accepted
   * @param list<string> $retried
   * @param list<string> $deadLettered
   * @param list<string> $lost lease lost on the follow-up write; result discarded
   */
  public function __construct(
    public readonly array $claimed = [],
    public readonly array $accepted = [],
    public readonly array $retried = [],
    public readonly array $deadLettered = [],
    public readonly array $lost = [],
  ) {}
}
