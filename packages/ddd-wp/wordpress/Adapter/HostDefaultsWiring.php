<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use Psr\Log\LoggerInterface;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;
use TangibleDDD\Runtime\SystemClock;

/**
 * ddd-wp init (register 1.3 R2, section 8 wave 2): fills HostDefaults with
 * the transitional WordPress implementations of the wave-1 ports, so the
 * optional trailing constructor parameters of the 0.6 classes (ProcessRunner,
 * CorrelationMiddleware, OutboxProcessor, OutboxIntegrationEventBus, ...)
 * resolve to WordPress behaviour and 0.6 compiled containers keep calling
 * the 0.6 constructors unchanged.
 *
 * Process-wide ports: IClock (SystemClock), ITransactionBoundary
 * (WpdbTransactionBoundary, Reject), IProcessLock (GET_LOCK, legacy name),
 * ISubscriptionRegistry (add_action), IInfrastructureSignalDispatcher (the
 * two legacy actions), ISubscriberProbe (has_action), IActorProvider,
 * IEnvironmentProvider, IOutboxOptionsReader, and a PSR-3 LoggerInterface
 * when psr/log is installed. Per-consumer ports go through WpHostPortFactory.
 *
 * Called by packages/ddd-wp/wordpress/hooks.php when it is included inside
 * WordPress (the winner's initializer at plugins_loaded:1, before any
 * consumer container compiles). Idempotent: a second call replaces the
 * instances with equivalent ones, except the subscription registry, which
 * is kept so its bindings are not forgotten.
 */
final class HostDefaultsWiring {

  public static function register(): void {
    HostDefaults::provide(IClock::class, new SystemClock());
    HostDefaults::provide(ITransactionBoundary::class, new WpdbTransactionBoundary());
    HostDefaults::provide(IProcessLock::class, new GetLockProcessLock());
    if (!HostDefaults::get(ISubscriptionRegistry::class) instanceof WpHookSubscriptionRegistry) {
      HostDefaults::provide(ISubscriptionRegistry::class, new WpHookSubscriptionRegistry());
    }
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, new WpHookSignalDispatcher());
    HostDefaults::provide(ISubscriberProbe::class, new HasActionSubscriberProbe());
    HostDefaults::provide(IActorProvider::class, new WpActorProvider());
    HostDefaults::provide(IEnvironmentProvider::class, new WpEnvironmentProvider());
    HostDefaults::provide(IOutboxOptionsReader::class, new WpOptionsOutboxConfigReader());
    HostDefaults::provide(IHostPortFactory::class, new WpHostPortFactory());

    if (interface_exists(LoggerInterface::class)) {
      HostDefaults::provide(LoggerInterface::class, new ErrorLogLogger());
    }
  }
}
