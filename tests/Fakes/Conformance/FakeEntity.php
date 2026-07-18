<?php

namespace TangibleDDD\Tests\Fakes\Conformance;

/** A rich domain object — exactly what must never ride an integration event ctor. */
final class FakeEntity {
  public function __construct(public readonly int $id) {}
}
