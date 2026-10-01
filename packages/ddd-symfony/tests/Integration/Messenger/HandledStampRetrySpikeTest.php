<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Messenger;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

/**
 * O13 spike (register O13, E open question 1, F9): on Symfony 7.4, when a
 * message with two handlers fails in one of them and Messenger retries it
 * through the Doctrine transport, does HandleMessageMiddleware skip the
 * handler that already succeeded?
 *
 * Answer (this test): YES. HandlerFailedException carries the envelope with
 * the HandledStamp of every handler that succeeded; the retry listener
 * re-sends THAT envelope, the stamps survive PhpSerializer and the Doctrine
 * table, and HandleMessageMiddleware skips any handler whose descriptor name
 * matches a HandledStamp. Caveats: the skip is by handler NAME, and every
 * closure handler is named "Closure", so with two closure handlers the
 * second never runs at all, not even on the first dispatch (pinned below);
 * and it only holds while the retried envelope is the one Messenger built,
 * so a message dispatched afresh with the same payload (a relay
 * re-submission, a manual re-send) runs every handler again.
 *
 * The design does not depend on it (X8): ddd-symfony sends ONE message per
 * fact with ONE handler (IntegrationFactHandler), and the per-(subscriber,
 * event_id) delivery ledger is the cross-host guarantee.
 */
final class HandledStampRetrySpikeTest extends PostgresTestCase {

  /** @var array<string, int> */
  public static array $runs = [];

  protected function setUp(): void {
    parent::setUp();
    self::$runs = ['a' => 0, 'b' => 0];
    $this->db->executeStatement('DROP TABLE IF EXISTS sf_spike_messages');
    $this->transport()->setup();
  }

  private function transport(): DoctrineTransport {
    $config = PostgreSqlConnection::buildConfiguration('doctrine://default?queue_name=spike&table_name=sf_spike_messages&auto_setup=false');
    return new DoctrineTransport(new PostgreSqlConnection($config, $this->db), new PhpSerializer());
  }

  /** @param list<HandlerDescriptor> $handlers */
  private function consume(DoctrineTransport $transport, array $handlers, int $messages): void {
    $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([SpikeMessage::class => $handlers]))]);
    $events = new EventDispatcher();
    $events->addSubscriber(new SendFailedMessageForRetryListener(
      new ServiceLocator(['spike' => static fn () => $transport]),
      new ServiceLocator(['spike' => static fn () => new MultiplierRetryStrategy(3, 0, 1, 0, 0)]),
    ));
    $events->addSubscriber(new StopWorkerOnMessageLimitListener($messages));
    $events->addSubscriber(new StopWorkerOnTimeLimitListener(10));
    (new Worker(['spike' => $transport], $bus, $events))->run(['sleep' => 10_000]);
  }

  public function test_a_retried_envelope_skips_the_handler_that_already_succeeded(): void {
    $transport = $this->transport();
    $transport->send(new Envelope(new SpikeMessage()));

    $this->consume($transport, [new HandlerDescriptor(new SpikeHandlerA()), new HandlerDescriptor(new SpikeHandlerB())], 2);

    self::assertSame(['a' => 1, 'b' => 2], self::$runs, 'A ran once; only B ran on the retry');
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM sf_spike_messages'), 'the retry succeeded and was acked');
  }

  public function test_the_retried_envelope_carries_the_handled_stamp_through_the_doctrine_table(): void {
    $transport = $this->transport();
    $transport->send(new Envelope(new SpikeMessage()));

    $this->consume($transport, [new HandlerDescriptor(new SpikeHandlerA()), new HandlerDescriptor(new SpikeHandlerB())], 1);

    $retried = iterator_to_array($transport->all());
    self::assertCount(1, $retried, 'the failure was re-sent for retry');
    $names = array_map(static fn (HandledStamp $s) => $s->getHandlerName(), $retried[0]->all(HandledStamp::class));
    self::assertSame([SpikeHandlerA::class . '::__invoke'], $names);
  }

  public function test_the_skip_is_by_handler_name_so_two_closures_collide(): void {
    $transport = $this->transport();
    $transport->send(new Envelope(new SpikeMessage()));
    $a = static function (SpikeMessage $m): void { HandledStampRetrySpikeTest::$runs['a']++; };
    $b = static function (SpikeMessage $m): void {
      if (++HandledStampRetrySpikeTest::$runs['b'] === 1) {
        throw new \RuntimeException('B fails once');
      }
    };

    self::assertSame('Closure', (new HandlerDescriptor($a))->getName());
    self::assertSame('Closure', (new HandlerDescriptor($b))->getName());

    $this->consume($transport, [new HandlerDescriptor($a), new HandlerDescriptor($b)], 1);

    self::assertSame(['a' => 1, 'b' => 0], self::$runs, 'both closures are named "Closure": A\'s HandledStamp makes B look handled, so B never runs, not even on the first dispatch');
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM sf_spike_messages'), 'and the message is acked as handled');
  }
}

/** @internal spike fixture */
final class SpikeMessage {}

/** @internal spike fixture */
final class SpikeHandlerA {
  public function __invoke(SpikeMessage $m): void {
    HandledStampRetrySpikeTest::$runs['a']++;
  }
}

/** @internal spike fixture */
final class SpikeHandlerB {
  public function __invoke(SpikeMessage $m): void {
    if (++HandledStampRetrySpikeTest::$runs['b'] === 1) {
      throw new \RuntimeException('B fails once');
    }
  }
}
