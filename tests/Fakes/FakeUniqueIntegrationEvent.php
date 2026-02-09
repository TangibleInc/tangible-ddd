<?php

namespace TangibleDDD\Tests\Fakes;

class FakeUniqueIntegrationEvent extends FakeIntegrationEvent {
  public function is_unique(): bool { return true; }
}
