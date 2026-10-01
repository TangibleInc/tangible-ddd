<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
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
 * - IProcessStore: the schema v8 WpdbProcessStore (ignition_key, version
 *   fencing, quarantine, stranded scan) for the framework ProcessRepository
 *   of a migrated consumer; otherwise WpRepositoryProcessStore over the
 *   IProcessRepository the runner was constructed with ($legacy), whoever
 *   implemented it (0.6 schema semantics).
 * - IWakeupScheduler: WpdbWakeupScheduler (intent rows + an AS projection
 *   on the legacy hooks at schedule time) for a migrated consumer, else the
 *   wave-2 ActionSchedulerWakeupScheduler (AS only). At schema v9 (wave 5)
 *   it is WpdbParkingScheduler, so a contended fact resume is parked (AW2).
 * - IOutboxStore: WpdbOutboxStore over the framework's own wpdb
 *   OutboxRepository ($legacy) of a migrated consumer (it needs v8's
 *   claim_token); an unmigrated consumer or a consumer-authored
 *   IOutboxRepository (LMS Doctrine) gets null, and its callers keep the
 *   0.6 path (R3).
 * - IOutboxAdministration: WpdbOutboxAdministration for the prefix, for any
 *   consumer identity (the repair commands carry only a prefix).
 * - IRelayPauseStore: WpRelayPauseStore (v8 pause rows + the 0.6 option).
 * - IDeliveryLedger: WpDeliveryLedger for a migrated consumer, else null.
 *
 * Only IDDDConfig consumers have WordPress storage; for an identity-only
 * consumer every answer is null and the caller falls back.
 */
final class WpHostPortFactory implements IHostPortFactory {

  public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
    if ($port === IProcessStore::class) {
      // The v8 store owns the SQL, so only for the framework's own
      // repository (exact class: a consumer subclass may override save()),
      // and only once the consumer's v8 migration has run.
      if ($legacy instanceof ProcessRepository && get_class($legacy) === ProcessRepository::class
        && $consumer instanceof IDDDConfig && WpSchema::is_v8($consumer)) {
        return new WpdbProcessStore($legacy, $consumer);
      }
      return $legacy instanceof IProcessRepository ? new WpRepositoryProcessStore($legacy, $consumer) : null;
    }

    if ($port === IOutboxAdministration::class) {
      // Prefix-addressed tables: works for an unregistered ("ghost") consumer too.
      return new WpdbOutboxAdministration($consumer->prefix());
    }

    if (!$consumer instanceof IDDDConfig) {
      return null;
    }

    if ($port === IOutboxStore::class) {
      // The store's claim / accept / retry_later / dead_letter SQL names
      // claim_token: only for a consumer whose v8 migration has run.
      return $legacy instanceof OutboxRepository && WpSchema::is_v8($consumer) ? new WpdbOutboxStore($legacy, $consumer) : null;
    }

    return match ($port) {
      IAuditSink::class => function_exists('TangibleDDD\\WordPress\\command_audit_enabled') && command_audit_enabled($consumer)
        ? new WpdbAuditSink($consumer)
        : new NullAuditSink(),
      IFactObserver::class => new TouchesFactObserver($consumer),
      IRelayPauseStore::class => new WpRelayPauseStore($consumer),
      IDeliveryLedger::class => WpSchema::is_v8($consumer) ? new WpDeliveryLedger($consumer->prefix()) : null,
      IWakeupScheduler::class => match (true) {
        WpSchema::is_v9($consumer) => new WpdbParkingScheduler($consumer), // AW2: parked fact resumes
        WpSchema::is_v8($consumer) => new WpdbWakeupScheduler($consumer),
        default => new ActionSchedulerWakeupScheduler($consumer),
      },
      default => null,
    };
  }
}
