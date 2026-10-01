<?php

namespace TangibleDDD\Application\Process;

final class LongProcessCatalog {

  /**
   * @param array<class-string<LongProcess>, list<array<string, mixed>>> $entries
   */
  public function __construct(private readonly array $entries = []) {}

  /**
   * @return array<class-string<LongProcess>, list<array<string, mixed>>>
   */
  public function all(): array {
    return $this->entries;
  }
}
