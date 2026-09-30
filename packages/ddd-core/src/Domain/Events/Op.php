<?php

namespace TangibleDDD\Domain\Events;

/**
 * The three verbs a fact can declare about state (spec appendix 9). Closed
 * vocabulary — and ORTHOGONAL to Kind: ops are adjectives on the act's
 * footprint, never causation nouns (Kind stays closed at three).
 *
 * The backing values are the at-rest dialect: they persist in the audit
 * row's events JSON and the touches table, picked once and mapped in
 * projection forever after (the causation_type ruling — columns outlive
 * fashions).
 */
enum Op: string {
  case Created = 'created';
  case Updated = 'updated';
  case Deleted = 'deleted';
}
