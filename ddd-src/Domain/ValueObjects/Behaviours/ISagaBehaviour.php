<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

/**
 * Marker interface for behaviour configs that represent a multi-phase saga step.
 */
interface ISagaBehaviour {
  public function no_phases(): int;
}


