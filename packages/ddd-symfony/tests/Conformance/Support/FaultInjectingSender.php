<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use TangibleDDD\Runtime\Delivery\TransportRejected;

/**
 * The Messenger Doctrine sender with two one-shot faults for the relay
 * scenarios: the next send() throws, or the next send() is "taken" but
 * yields no transport message id (and so inserts nothing). Every other
 * send() goes to the real Doctrine transport.
 */
final class FaultInjectingSender implements SenderInterface {

  private ?\Throwable $rejectNext = null;

  private bool $noIdNext = false;

  public function __construct(private readonly SenderInterface $inner) {}

  public function rejectNext(?\Throwable $e = null): void {
    $this->rejectNext = $e ?? new TransportRejected('injected rejection');
  }

  public function noIdNext(): void {
    $this->noIdNext = true;
  }

  public function send(Envelope $envelope): Envelope {
    if ($this->rejectNext !== null) {
      $e = $this->rejectNext;
      $this->rejectNext = null;
      throw $e;
    }
    if ($this->noIdNext) {
      $this->noIdNext = false;
      return $envelope;
    }
    return $this->inner->send($envelope);
  }
}
