<?php

namespace TangibleDDD\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;
use TangibleDDD\Tests\Fakes\FakePayload;

/**
 * ProcessRepository decodes the payload column with assoc=true and feeds the
 * resulting ARRAY through deserialize_polymorphic → from_json →
 * sync_init_state. The object path (stdClass) always worked; the array path
 * fataled on property_exists(array) — every suspended process with a payload
 * died on rehydrate (find_waiting_for / resume).
 */
class JsonLifecycleValueArrayPathTest extends TestCase {

  public function test_from_json_accepts_an_assoc_array(): void {
    $payload = FakePayload::from_json(['data' => 'x', 'counter' => 3]);

    $this->assertSame('x', $payload->data);
    $this->assertSame(3, $payload->counter);
  }

  public function test_polymorphic_round_trip_through_assoc_decode(): void {
    $original = new FakePayload(data: 'suspended-state', counter: 7);

    // Serialize the way ProcessRepository persists, decode the way it reads.
    $stored  = json_encode([
      '_class' => FakePayload::class,
      '_data'  => json_decode($original->to_json(), true),
    ]);
    $revived = JsonLifecycleValue::deserialize_polymorphic(json_decode($stored, true));

    $this->assertInstanceOf(FakePayload::class, $revived);
    $this->assertSame('suspended-state', $revived->data);
    $this->assertSame(7, $revived->counter);
  }
}
