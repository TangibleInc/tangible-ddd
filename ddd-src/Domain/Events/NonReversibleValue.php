<?php

namespace TangibleDDD\Domain\Events;

use TangibleDDD\Domain\Exceptions\IncorrectUsage;

final class NonReversibleValue extends IncorrectUsage {
  public function __construct(string $event_class, string $param, string $actual_type) {
    parent::__construct(
      "$event_class::\$$param holds $actual_type — integration events are composed of reversible values only " .
      "(int|string|bool|float|null, BackedEnum, DateTimeInterface, arrays thereof). " .
      "Fat facts belong on a DomainEvent that announces a scalar record (IAnnouncesIntegration)."
    );
  }
}
