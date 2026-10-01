<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\Audit\NullAuditSink;
use TangibleDDD\Runtime\IFactObserver;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;

use function TangibleDDD\WordPress\command_audit_enabled;

/**
 * The wp per-consumer port factory (CR-SP-2), registered in HostDefaults at
 * ddd-wp init:
 *
 * - IAuditSink: WpdbAuditSink when the consumer's `command_audit` table
 *   exists (0.6 command_audit_enabled(), cached per prefix), else
 *   NullAuditSink (0.6 "audit off": the guard still runs, nothing is written).
 * - IFactObserver: the touches indexer for the consumer.
 * - IProcessStore: WpdbProcessStore over the IProcessRepository the runner
 *   was constructed with ($legacy), whoever implemented it.
 * - IWakeupScheduler: Action Scheduler on the consumer's legacy hooks.
 *
 * Only IDDDConfig consumers have WordPress storage; for an identity-only
 * consumer every answer is null and the caller falls back.
 */
final class WpHostPortFactory implements IHostPortFactory {

  public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
    if ($port === IProcessStore::class) {
      return $legacy instanceof IProcessRepository ? new WpdbProcessStore($legacy, $consumer) : null;
    }

    if (!$consumer instanceof IDDDConfig) {
      return null;
    }

    return match ($port) {
      IAuditSink::class => function_exists('TangibleDDD\\WordPress\\command_audit_enabled') && command_audit_enabled($consumer)
        ? new WpdbAuditSink($consumer)
        : new NullAuditSink(),
      IFactObserver::class => new TouchesFactObserver($consumer),
      IWakeupScheduler::class => new ActionSchedulerWakeupScheduler($consumer),
      default => null,
    };
  }
}
