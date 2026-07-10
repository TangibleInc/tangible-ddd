<?php

namespace TangibleDDD\Application\Process;

final class AwaitedEventNotRegistered extends \LogicException {
  public function __construct(string $event_class, string $process_class) {
    parent::__construct(
      "$process_class suspends on $event_class, but no wake-up hook is registered for it. " .
      "Declare it: #[Awaits($event_class::class)] on the process class (or the ddd.long_process " .
      "tag's awaits: list). Without registration the saga would sleep forever."
    );
  }
}
