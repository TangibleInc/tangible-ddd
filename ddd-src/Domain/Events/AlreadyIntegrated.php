<?php

namespace TangibleDDD\Domain\Events;

use TangibleDDD\IncorrectUsage;

final class AlreadyIntegrated extends IncorrectUsage {
  public function __construct(string $event_class, string $event_id) {
    parent::__construct(
      "$event_class (event_id: $event_id) already traveled — you are re-raising a reconstruction. " .
      "Re-delivery of a traveled fact is REPLAY (through the outbox), never raising."
    );
  }
}
