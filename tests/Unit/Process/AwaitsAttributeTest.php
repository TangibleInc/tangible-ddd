<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AwaitsAttributeTest extends TestCase {

  public function test_attribute_is_readable_from_class(): void {
    $attrs = (new \ReflectionClass(FakeGatherProcess::class))->getAttributes(Awaits::class);
    $this->assertNotEmpty($attrs);
    $this->assertSame(FakeResolvedEvent::class, $attrs[0]->newInstance()->event_class);
  }
}
