<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakePayload;

class ResultTest extends TestCase {

  public function test_empty_result(): void {
    $result = new Result();

    $this->assertNull($result->payload);
    $this->assertEmpty($result->commands);
    $this->assertNull($result->await);
    $this->assertNull($result->checkpoint);
    $this->assertFalse($result->should_suspend());
  }

  public function test_result_with_payload(): void {
    $payload = new FakePayload('test', 1);
    $result = new Result(payload: $payload);

    $this->assertSame($payload, $result->payload);
    $this->assertSame('test', $result->payload->data);
  }

  public function test_result_with_commands(): void {
    $cmd1 = new \stdClass();
    $cmd2 = new \stdClass();
    $result = new Result(commands: [$cmd1, $cmd2]);

    $this->assertCount(2, $result->commands);
  }

  public function test_result_with_await(): void {
    $await = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);
    $result = new Result(await: $await);

    $this->assertTrue($result->should_suspend());
    $this->assertSame(FakeIntegrationEvent::class, $result->await->event_class);
    $this->assertSame(['entity_id' => 42], $result->await->match_criteria);
  }

  public function test_result_with_checkpoint(): void {
    $cp = new FakePayload('checkpoint_data', 99);
    $result = new Result(checkpoint: $cp);

    $this->assertSame($cp, $result->checkpoint);
    $this->assertSame('checkpoint_data', $result->checkpoint->data);
  }

  public function test_result_with_all_fields(): void {
    $payload = new FakePayload('data', 1);
    $checkpoint = new FakePayload('cp', 2);
    $await = new AwaitEvent(FakeIntegrationEvent::class);
    $cmds = [new \stdClass()];

    $result = new Result(
      payload: $payload,
      commands: $cmds,
      await: $await,
      checkpoint: $checkpoint,
    );

    $this->assertSame($payload, $result->payload);
    $this->assertTrue($result->should_suspend());
    $this->assertSame($checkpoint, $result->checkpoint);
  }
}
