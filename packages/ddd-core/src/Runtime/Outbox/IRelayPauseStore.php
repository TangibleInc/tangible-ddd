<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * Relay pauses by holder (register 3.4, C25). One row per (holder, selector);
 * a later hold() for the same pair replaces it. A selector is an exact event
 * type, `*`, or a glob such as `acme_order_*`.
 *
 * Error behaviour: hold()/release() throw on a storage failure; isPaused()
 * never throws for a well-formed store and honours expiry (`until` <= now
 * means released).
 *
 * Lifetime: durable rows; wp reads the legacy `{prefix}_outbox_pauses`
 * option until it is drained.
 */
interface IRelayPauseStore {

  public function hold(string $holder, string $selector, ?\DateTimeImmutable $until): void;

  /** Release one selector of $holder, or every selector when null. */
  public function release(string $holder, ?string $selector = null): void;

  public function isPaused(string $eventType, \DateTimeImmutable $now): bool;
}
