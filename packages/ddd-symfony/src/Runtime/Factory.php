<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use Doctrine\DBAL\Connection;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;

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

  /**
   * The core OutboxIntegrationEventBus in its port form (CONF-2: store +
   * clock, absolute UTC due_at), behind the sf class-recording decorator
   * (CR sf-1). The fact observer resolves from HostDefaults per consumer,
   * else NullFactObserver (O4).
   */
  public static function integrationBus(IOutboxStore $store, IClock $clock, IDDDConfig $consumer, OutboxConfig $config): FactClassRecordingEventBus {
    return new FactClassRecordingEventBus(
      new OutboxIntegrationEventBus(null, $consumer, null, $clock, $store, $config),
      $store instanceof DbalPostgresOutboxStore ? $store : null,
    );
  }

  /**
   * The process lock: core ReentrantProcessLock over the Postgres session
   * advisory lock (5.2), registered with the RuntimeReset lock guard so a
   * lock held across a message boundary is reported and force-released.
   */
  public static function processLock(Connection $connection, string $poolerPolicy = 'warn', ?LoggerInterface $logger = null): ReentrantProcessLock {
    $lock = new ReentrantProcessLock(
      new PostgresAdvisoryProcessLock($connection, $logger, PoolerPolicy::from($poolerPolicy)),
      RuntimeLog::argument(ReentrantProcessLock::class, 'log', $logger),
    );
    RuntimeReset::guardLock($lock);
    return $lock;
  }

  /**
   * The core ProcessRunner on the sf ports. $inbandStart false (the sf
   * default, X3/5.2) asks for start() = persist + Continue intent in the
   * caller's transaction, first step in a worker; it is passed through the
   * core constructor option named by startModeParameter(). A core runner
   * without that option runs the first step in-band; that is logged as a
   * warning at construction, never silent (CR sfp-1).
   */
  public static function processRunner(
    IDDDConfig $consumer,
    IProcessLock $lock,
    IProcessStore $store,
    IWakeupScheduler $wakeups,
    ISubscriptionRegistry $subscriptions,
    ITransactionBoundary $boundary,
    IClock $clock,
    bool $inbandStart = false,
    ?LoggerInterface $logger = null,
  ): ProcessRunner {
    $args = [
      'config' => $consumer,
      'repository' => null,
      'lock' => $lock,
      'store' => $store,
      'wakeups' => $wakeups,
      'subscriptions' => $subscriptions,
      'boundary' => $boundary,
      'clock' => $clock,
    ];
    $option = self::startModeParameter(ProcessRunner::class);
    if ($option !== null) {
      $args[$option] = $inbandStart;
    } elseif (!$inbandStart) {
      $logger?->warning(
        '[ddd process] this ddd-core ProcessRunner has no start-mode option: start() runs the first step in-band, '
        . 'not as persist + Continue intent (tangible_ddd.process.inband_start: false is not honoured until core ships CR sfp-1)'
      );
    }
    return new ProcessRunner(...$args);
  }

  /** The name of a ProcessRunner-style constructor's in-band start option, null when it has none. */
  public static function startModeParameter(string $class): ?string {
    foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
      if (in_array($param->getName(), ['inbandStart', 'inband_start'], true)) {
        return $param->getName();
      }
    }
    return null;
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
