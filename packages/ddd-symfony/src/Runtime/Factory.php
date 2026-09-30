<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\OrderedListenerDispatcher;

/**
 * Container factories for core runtime objects whose constructors the bundle
 * must not pin (the wave-2 logger switch; RuntimeLog).
 *
 * @internal
 */
final class Factory {

  public static function delivery(ISubscriptionRegistry $registry, IDeliveryLedger $ledger, int $budget, ?LoggerInterface $logger = null): IntegrationDelivery {
    return new IntegrationDelivery($registry, $ledger, $budget, RuntimeLog::argument(IntegrationDelivery::class, 'log', $logger));
  }

  /** @param array<string, int|float> $relay */
  public static function outboxConfig(array $relay): OutboxConfig {
    return new OutboxConfig(
      batch_size: (int) $relay['batch_size'],
      max_attempts: (int) $relay['max_attempts'],
      base_retry_delay_seconds: (int) $relay['base_retry_delay_seconds'],
      retry_multiplier: (float) $relay['retry_multiplier'],
      max_retry_delay_seconds: (int) $relay['max_retry_delay_seconds'],
      processor_interval_seconds: (int) $relay['idle_sleep_seconds'],
      lock_timeout_seconds: (int) $relay['lease_seconds'],
    );
  }

  /**
   * @param list<array{0: string, 1: int, 2: string, 3: string}> $listeners [event, priority, service id, method]
   */
  public static function domainDispatcher(array $listeners, ContainerInterface $locator): OrderedListenerDispatcher {
    $dispatcher = new OrderedListenerDispatcher();
    foreach ($listeners as [$event, $priority, $id, $method]) {
      $dispatcher->listen(
        $event,
        static function (object $e) use ($locator, $id, $method): void {
          $locator->get($id)->{$method}($e);
        },
        $priority,
        $id . '::' . $method,
      );
    }
    return $dispatcher;
  }
}
