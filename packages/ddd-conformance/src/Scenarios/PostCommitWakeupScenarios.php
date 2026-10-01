<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\PostCommitWakeups;

/**
 * D14 post-commit relay wakeup (register section 4 `wakeup.post-commit`;
 * sf only, transactional NOTIFY). Needs PostCommitWakeups (CR-W4C4-5).
 */
abstract class PostCommitWakeupScenarios extends ConformanceTestCase {

  /** "delivered < 1 s with NOTIFY" (register section 4). */
  protected const WAKEUP_LATENCY_SECONDS = 1.0;

  /** Slack on top of the poll interval for the poll fallback. */
  protected const POLL_SLACK_SECONDS = 1.0;

  #[Group('wakeup.post-commit')]
  #[TestDox('wakeup.post-commit: a commit wakes the idle relay worker (delivered < 1 s); with the wakeup suppressed the poll delivers within its interval; a rollback wakes nothing and relays nothing')]
  public function test_wakeup_post_commit(): void {
    $wakeups = $this->wakeups();
    $poll = $wakeups->poll_seconds();
    self::assertGreaterThan(self::WAKEUP_LATENCY_SECONDS, $poll, 'the poll interval must be longer than the wakeup bound, or the two paths are indistinguishable');

    try {
      $wakeups->start_relay();

      // 1. With the wakeup: the idle worker reacts to the commit.
      $id = $this->publish(new WidgetRegistered('w-1'));
      $elapsed = $wakeups->relay_until($id, $poll + self::POLL_SLACK_SECONDS);
      self::assertNotNull($elapsed, 'delivered');
      self::assertLessThan(self::WAKEUP_LATENCY_SECONDS, $elapsed, 'woken by the commit, not by the poll');

      // 2. Wakeup lost: the poll still delivers, within its interval.
      $wakeups->drop_next_wakeup();
      $id = $this->publish(new WidgetRegistered('w-2'));
      self::assertFalse($wakeups->await_wakeup(0.0), 'no wakeup was sent');
      $elapsed = $wakeups->relay_until($id, $poll + self::POLL_SLACK_SECONDS);
      self::assertNotNull($elapsed, 'the poll recovered the lost wakeup');
      self::assertLessThanOrEqual($poll + self::POLL_SLACK_SECONDS, $elapsed);

      // 3. Rollback: no wakeup, nothing to relay.
      $bus = $this->host->command_bus([CreateWidget::class => function (): void {
        $this->host->events()->record(new WidgetRegistered('w-3'));
        throw new \RuntimeException('rolled back after staging the fact');
      }]);
      self::assertNotNull(self::thrown(static fn () => $bus->handle(new CreateWidget('w-3'))));
      self::assertFalse($wakeups->await_wakeup(self::WAKEUP_LATENCY_SECONDS), 'a rolled-back transaction sends no wakeup');
      $stats = $this->host->outbox_admin()->stats();
      self::assertSame(0, $stats['pending'], 'nothing to relay');
      self::assertSame(2, $stats['accepted']);
    } finally {
      $wakeups->stop_relay();
    }
  }

  protected function wakeups(): PostCommitWakeups {
    if (!$this->host instanceof PostCommitWakeups) {
      $this->skip_for('CR-W4C4-5', 'the host fixture does not implement PostCommitWakeups yet');
    }
    return $this->host;
  }
}
