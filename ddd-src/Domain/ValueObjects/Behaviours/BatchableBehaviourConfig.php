<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

/**
 * Base config for behaviours that operate on a batch of items (often IDs).
 */
abstract class BatchableBehaviourConfig extends BaseBehaviourConfig {
  /**
   * @param array $batch Usually an array of IDs
   */
  public function __construct(public readonly array $batch = []) {
    parent::__construct();
  }

  abstract public function get_default_batch_size(): int;

  /**
   * Return the same config but with a batch payload.
   */
  abstract public function clone_with_batch(array $batch): static;
}


