<?php

namespace TangibleDDD\Tests\Fakes;

class FakeDelayedIntegrationEvent extends FakeIntegrationEvent {
  public function delay(): int { return 60; }
}
