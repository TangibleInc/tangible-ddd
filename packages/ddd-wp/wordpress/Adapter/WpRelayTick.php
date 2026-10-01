<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\SystemClock;

/**
 * One wp relay tick (register 5.3, 3.6; WPC-1): what the recurring
 * `{prefix}_outbox_process` action and `wp ddd relay --once` run.
 *
 * 1. The relay batch. On a schema v8 consumer whose container uses the
 *    framework's own OutboxRepository and ActionSchedulerOutboxPublisher,
 *    the port-form core OutboxProcessor over WpdbOutboxStore (fenced
 *    claim_token claims, the host clock) and ActionSchedulerTransport
 *    (submit + accept in one wpdb transaction). Otherwise (a consumer's own
 *    repository, a routing or external publisher, a consumer not yet
 *    migrated) the container's 0.6-form OutboxProcessor, unchanged.
 * 2. Re-projection of due wakeup intents whose Action Scheduler action is
 *    gone (WpdbWakeupScheduler::reproject()).
 * 3. The stranded scan (WpStrandedScan) over the framework process table.
 *
 * Steps 2 and 3 run only on a schema v8 consumer with the framework
 * ProcessRepository. Each step's failure is caught and reported; one
 * step never blocks the next.
 */
final class WpRelayTick {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly OutboxProcessor $relay,
    private readonly bool $portForm,
    private readonly ?WpdbWakeupScheduler $wakeups,
    private readonly ?WpStrandedScan $stranded,
    private readonly ?IClock $clock = null,
  ) {}

  /** @param object $container the consumer's container (get()/has()) */
  public static function for(IDDDConfig $config, object $container, ?IClock $clock = null): self {
    $v8 = WpSchema::isV8($config);
    $repository = self::service($container, IOutboxRepository::class);
    $publisher = self::service($container, IOutboxPublisher::class);

    $portForm = $v8
      && $repository instanceof OutboxRepository && get_class($repository) === OutboxRepository::class
      && ($publisher === null || get_class($publisher) === ActionSchedulerOutboxPublisher::class);

    if ($portForm) {
      $outboxConfig = self::service($container, OutboxConfig::class) ?? OutboxConfig::from_options($config);
      $logger = HostDefaults::get(LoggerInterface::class);
      $relay = new OutboxProcessor(
        $config, null, $outboxConfig, null,
        HostDefaults::get(ISubscriberProbe::class),
        $logger instanceof LoggerInterface ? $logger : null,
        $clock,
        new WpdbOutboxStore($repository, $config, $clock),
        new ActionSchedulerTransport($config->as_group('outbox')),
        new WpdbTransactionBoundary(),
      );
    } else {
      $relay = $container->get(OutboxProcessor::class);
    }

    $wakeups = $stranded = null;
    $processes = self::service($container, IProcessRepository::class);
    if ($v8 && $processes instanceof ProcessRepository && get_class($processes) === ProcessRepository::class) {
      $scheduler = HostDefaults::for(IWakeupScheduler::class, $config);
      if ($scheduler instanceof WpdbWakeupScheduler) {
        $wakeups = $scheduler;
        $stranded = new WpStrandedScan($config, new WpdbProcessStore($processes, $config, $clock), $scheduler, $clock);
      }
    }

    return new self($config, $relay, $portForm, $wakeups, $stranded, $clock);
  }

  public function run(): WpRelayTickReport {
    $errors = [];
    $relay = null;
    try {
      $relay = $this->relay->process_batch();
    } catch (\Throwable $e) {
      $errors['relay'] = $e->getMessage();
    }

    $reprojected = null;
    if ($this->wakeups !== null) {
      try {
        $reprojected = $this->wakeups->reproject($this->now());
      } catch (\Throwable $e) {
        $errors['wakeups'] = $e->getMessage();
      }
    }

    $stranded = null;
    if ($this->stranded !== null) {
      try {
        $stranded = $this->stranded->run();
      } catch (\Throwable $e) {
        $errors['stranded'] = $e->getMessage();
      }
    }

    return new WpRelayTickReport($this->config->prefix(), $this->portForm, $relay, $reprojected, $stranded, $errors);
  }

  public function isPortForm(): bool {
    return $this->portForm;
  }

  private function now(): \DateTimeImmutable {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now();
  }

  private static function service(object $container, string $id): ?object {
    try {
      if (method_exists($container, 'has') && !$container->has($id)) {
        return null;
      }
      $service = $container->get($id);
      return is_object($service) ? $service : null;
    } catch (\Throwable) {
      return null;
    }
  }
}
