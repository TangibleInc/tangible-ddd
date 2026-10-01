<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;
use TangibleDDD\Symfony\Runtime\Wakeup\ProcessRunnerWakeTarget;
use TangibleDDD\Symfony\Runtime\Wakeup\WakeKindUnsupported;

final class ProcessRunnerWakeTargetTest extends TestCase {

  private function at(): \DateTimeImmutable {
    return new \DateTimeImmutable('2026-10-01T12:00:00Z');
  }

  public function test_without_a_wake_door_continue_and_timeout_use_the_runner_doors(): void {
    $runner = new class {
      public array $calls = [];
      public function continue_scheduled(int $id): void { $this->calls[] = ['continue', $id]; }
      public function handle_timeout(int $id, int $step): void { $this->calls[] = ['timeout', $id, $step]; }
    };
    $target = new ProcessRunnerWakeTarget($runner);

    $target->wake(WakeupIntent::continuation('acme', 4, 1, $this->at()));
    $target->wake(WakeupIntent::timeout('acme', 5, 2, $this->at()));

    self::assertSame([['continue', 4], ['timeout', 5, 2]], $runner->calls);
  }

  public function test_resume_retry_without_a_wake_door_is_unsupported_not_dropped(): void {
    $target = new ProcessRunnerWakeTarget(new class {
      public function continue_scheduled(int $id): void {}
    });

    $this->expectException(WakeKindUnsupported::class);
    $target->wake(new WakeupIntent(WakeKind::ResumeRetry, 'acme', 4, 1, 'suspended', $this->at(), 'resume_retry:4:1'));
  }

  public function test_a_runner_with_a_wake_door_gets_every_kind(): void {
    $runner = new class {
      public array $woken = [];
      public function wake(WakeupIntent $i): void { $this->woken[] = $i->key; }
      public function continue_scheduled(int $id): void { throw new \LogicException('must not be used'); }
    };

    (new ProcessRunnerWakeTarget($runner))->wake(new WakeupIntent(WakeKind::ResumeRetry, 'acme', 4, 1, null, $this->at(), 'r:4'));
    (new ProcessRunnerWakeTarget($runner))->wake(WakeupIntent::continuation('acme', 4, 1, $this->at()));

    self::assertSame(['r:4', 'continue:4:1'], $runner->woken);
  }

  public function test_the_message_round_trips_its_claim_through_the_php_serializer(): void {
    $claim = new ClaimedWakeup(WakeupIntent::timeout('acme', 9, 3, $this->at()), 'tok-1', $this->at()->modify('+300 seconds'), 2);
    $serializer = new PhpSerializer();

    $decoded = $serializer->decode($serializer->encode(new Envelope(ProcessWakeupMessage::from_claim($claim))))->getMessage();

    self::assertInstanceOf(ProcessWakeupMessage::class, $decoded);
    self::assertEquals($claim, $decoded->to_claim());
  }
}
