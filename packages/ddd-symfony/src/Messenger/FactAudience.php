<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;

/**
 * Wave 5: another consumer of the same app, as the relay of a fact's raiser
 * sees it: its prefix, its compiled subscription map (asked with
 * has_subscribers(), which builds nothing) and the sender of its facts
 * transport. A fact the map subscribes to is copied to that sender,
 * addressed to the consumer.
 */
final class FactAudience {

  public function __construct(
    public readonly string $consumer,
    public readonly CompiledSubscriptionRegistry $subscriptions,
    public readonly SenderInterface $sender,
  ) {}
}
