<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TangibleDDD\Application\Infrastructure\AuditSinkFailed;
use TangibleDDD\Symfony\Runtime\DddSignal;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Runtime\SymfonySignalDispatcher;
use TangibleDDD\Symfony\Tests\Support\RecordingLogger;

/** Wave-2 carry-over: in a Symfony app infrastructure signals reach the PSR logger and the event dispatcher, never error_log. */
final class SymfonySignalDispatcherTest extends TestCase {

  public function test_a_signal_is_logged_and_dispatched_as_a_ddd_signal_event(): void {
    $log = new RecordingLogger();
    $events = new EventDispatcher();
    $seen = [];
    $events->addListener(DddSignal::class, static function (DddSignal $s) use (&$seen): void {
      $seen[] = $s;
    });
    $consumer = new SymfonyConsumerConfig('acme', 'App');
    $signal = new AuditSinkFailed('cmd-1', 'corr-1', 'close', 'audit db gone');

    (new SymfonySignalDispatcher($log, $events))->emit($signal, $consumer);

    self::assertCount(1, $seen);
    self::assertSame($signal, $seen[0]->event);
    self::assertSame('acme', $seen[0]->consumer->prefix());
    self::assertSame($signal::action(), $seen[0]->action());
    self::assertCount(1, $log->records);
    self::assertStringContainsString('acme_' . $signal::action(), $log->records[0]['message']);
    self::assertSame($signal, $log->records[0]['context']['signal']);
  }

  public function test_a_throwing_listener_never_breaks_the_emitter(): void {
    $log = new RecordingLogger();
    $events = new EventDispatcher();
    $events->addListener(DddSignal::class, static function (): void {
      throw new \RuntimeException('listener bug');
    });

    (new SymfonySignalDispatcher($log, $events))->emit(new AuditSinkFailed('c', 'k', 'open', 'x'), new SymfonyConsumerConfig('acme', 'App'));

    self::assertStringContainsString('listener bug', $log->text());
  }

  public function test_without_an_event_dispatcher_it_only_logs(): void {
    $log = new RecordingLogger();

    (new SymfonySignalDispatcher($log))->emit(new AuditSinkFailed('c', 'k', 'open', 'x'), new SymfonyConsumerConfig('acme', 'App'));

    self::assertCount(1, $log->records);
  }
}
