<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;

final class InMemoryWakeupSchedulerTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $tx;
  private InMemoryWakeupScheduler $scheduler;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->tx = new InMemoryTransactionBoundary();
    $this->scheduler = new InMemoryWakeupScheduler($this->tx);
    $this->tx->enlist($this->scheduler);
  }

  private function schedule(WakeupIntent $i): void {
    $this->tx->run(fn () => $this->scheduler->schedule($i));
  }

  public function test_intent_helpers_build_the_frozen_idempotency_keys(): void {
    $due = $this->clock->now()->modify('+1 hour');
    $t = WakeupIntent::timeout('acme', 7, 2, $due);
    $c = WakeupIntent::continuation('acme', 7, 3, $due);

    self::assertSame(WakeKind::Timeout, $t->kind);
    self::assertSame('timeout:7:2', $t->idempotencyKey);
    self::assertSame('suspended', $t->expectedStatus);
    self::assertSame(WakeKind::Continue, $c->kind);
    self::assertSame('continue:7:3', $c->idempotencyKey);
    self::assertSame('scheduled', $c->expectedStatus);
    self::assertSame('timeout', WakeKind::Timeout->value);
    self::assertSame('resume_retry', WakeKind::ResumeRetry->value);
  }

  public function test_intent_due_at_is_normalised_to_utc(): void {
    $i = WakeupIntent::timeout('acme', 1, 0, new \DateTimeImmutable('2026-10-01 14:00:00', new \DateTimeZone('Europe/Berlin')));
    self::assertSame('2026-10-01T12:00:00+00:00', $i->dueAt->format(DATE_ATOM));
  }

  public function test_schedule_outside_a_transaction_throws(): void {
    self::assertInstanceOf(IWakeupScheduler::class, $this->scheduler);
    $this->expectException(WakeupOutsideTransaction::class);
    $this->scheduler->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
  }

  public function test_cancel_outside_a_transaction_throws(): void {
    $this->expectException(WakeupOutsideTransaction::class);
    $this->scheduler->cancel('any');
  }

  public function test_the_constructor_requires_a_boundary(): void {
    $param = (new \ReflectionMethod(InMemoryWakeupScheduler::class, '__construct'))->getParameters()[0];
    self::assertFalse($param->allowsNull(), 'leniency must be an explicit factory choice');
    self::assertFalse($param->isOptional());
  }

  public function test_lenient_mode_is_an_explicit_named_factory(): void {
    $lenient = InMemoryWakeupScheduler::withoutTransactionCheck();

    $lenient->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
    self::assertCount(1, $lenient->pending());

    $lenient->cancel(WakeupIntent::timeout('acme', 1, 0, $this->clock->now())->idempotencyKey);
    self::assertSame([], $lenient->pending());
  }

  public function test_an_intent_rolls_back_with_the_process_save(): void {
    try {
      $this->tx->run(function () {
        $this->scheduler->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
        throw new \RuntimeException('save failed');
      });
    } catch (\RuntimeException) {
    }
    self::assertSame([], $this->scheduler->claimDue($this->clock->now(), 10, 30));
  }

  public function test_due_intents_are_claimed_once_in_due_order(): void {
    $this->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()->modify('+10 seconds')));
    $this->schedule(WakeupIntent::timeout('acme', 2, 0, $this->clock->now()->modify('+5 seconds')));
    $this->schedule(WakeupIntent::timeout('acme', 3, 0, $this->clock->now()->modify('+25 hours')));

    self::assertSame([], $this->scheduler->claimDue($this->clock->now(), 10, 30));

    $this->clock->advance('PT10S');
    $claimed = $this->scheduler->claimDue($this->clock->now(), 10, 30);
    self::assertSame([2, 1], array_map(static fn ($w) => $w->intent->processId, $claimed));
    self::assertSame([], $this->scheduler->claimDue($this->clock->now(), 10, 30), 'leased');
  }

  public function test_duplicate_idempotency_key_is_a_no_op(): void {
    $this->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
    $this->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()->modify('+1 day')));

    $claimed = $this->scheduler->claimDue($this->clock->now(), 10, 30);
    self::assertCount(1, $claimed);
    self::assertEquals($this->clock->now(), $claimed[0]->intent->dueAt, 'the first intent wins');
  }

  public function test_cancel_removes_the_intent(): void {
    $this->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
    $this->tx->run(fn () => $this->scheduler->cancel('timeout:1:0'));

    self::assertSame([], $this->scheduler->claimDue($this->clock->now(), 10, 30));
    self::assertSame([], $this->scheduler->pending());
  }

  public function test_complete_is_fenced_by_the_claim_token(): void {
    $this->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
    [$a] = $this->scheduler->claimDue($this->clock->now(), 1, 30);

    $this->clock->advance('PT31S');
    [$b] = $this->scheduler->claimDue($this->clock->now(), 1, 30);

    self::assertFalse($this->scheduler->complete($a));
    self::assertTrue($this->scheduler->complete($b));
    self::assertSame([], $this->scheduler->pending());
  }

  public function test_retry_later_counts_attempts_and_delays(): void {
    $this->schedule(WakeupIntent::timeout('acme', 1, 0, $this->clock->now()));
    [$w] = $this->scheduler->claimDue($this->clock->now(), 1, 30);

    self::assertTrue($this->scheduler->retryLater($w, 'LockNotAcquired', $this->clock->now()->modify('+2 seconds')));
    self::assertSame([], $this->scheduler->claimDue($this->clock->now(), 1, 30));

    $this->clock->advance('PT2S');
    [$again] = $this->scheduler->claimDue($this->clock->now(), 1, 30);
    self::assertSame(1, $again->attempts);
    self::assertFalse($this->scheduler->retryLater($w, 'late', $this->clock->now()), 'stale claim');
  }
}
