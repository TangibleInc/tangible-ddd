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
 *
 * Late WordPress (register_lazily): when hooks.php is included before
 * WordPress is up (the loader runs from vendor/autoload.php, and a consumer
 * test bootstrap loads WorDBless or its own add_action/get_option stubs
 * afterwards), it registers a HostDefaults miss resolver instead. The first
 * HostDefaults miss after WordPress functions exist fills every port this
 * class wires that is still unprovided (fill_missing) and removes the
 * resolver, so the 0.6 constructors and OutboxConfig::from_options() get
 * WordPress behaviour wherever 0.6.5 had it, with no plugins_loaded.
 */
final class HostDefaultsWiring {

  public static function register(): void {
    foreach (self::defaults() as $port => $make) {
      if ($port === ISubscriptionRegistry::class
        && HostDefaults::has(ISubscriptionRegistry::class)
        && HostDefaults::get(ISubscriptionRegistry::class) instanceof WpHookSubscriptionRegistry) {
        continue;
      }
      HostDefaults::provide($port, $make());
    }
  }

  /**
   * Provide each WordPress default whose port has nothing yet; never replaces
   * an implementation a host or test provided explicitly.
   */
  private static function fill_missing(): void {
    foreach (self::defaults() as $port => $make) {
      if (!HostDefaults::has($port)) {
        HostDefaults::provide($port, $make());
      }
    }
  }

  /**
   * Install the one-shot miss resolver described in the class doc. Called by
   * hooks.php when it is included with no WordPress functions defined yet;
   * idempotent (a second call replaces the resolver with an equivalent one).
   */
  public static function register_lazily(): void {
    HostDefaults::on_miss(static function (): void {
      if (!self::wordpress_is_present()) {
        return;
      }
      HostDefaults::on_miss(null);
      self::fill_missing();
    });
  }

  /**
   * WordPress that never boots (wave 5): the loader saw add_action, so it
   * deferred the winner's initializer to plugins_loaded, which a consumer
   * test bootstrap never fires; hooks.php never runs. Installed through
   * wire_unbooted() (wordpress/unbooted.php) at the loader's include time,
   * this one-shot miss resolver fills every unprovided WordPress default on
   * the first HostDefaults miss, like register_lazily(), guarded to the
   * winner's classes:
   *
   * - once a winner has initialized, its own hooks.php owns the wiring:
   *   the resolver removes itself and provides nothing;
   * - it wires only when $root is the distribution whose classes are
   *   loaded (HostDefaults itself is under $root) and, when copies have
   *   registered, the latest of them; otherwise it stays in place for the
   *   copy that is.
   *
   * @param string $root the distribution root of the loader that installs it
   */
  public static function register_unbooted(string $root): void {
    $root = rtrim($root, '/') . '/';
    HostDefaults::on_miss(static function () use ($root): void {
      if (class_exists('Tangible_DDD_Versions', false) && \Tangible_DDD_Versions::instance()->is_initialized()) {
        HostDefaults::on_miss(null);
        return;
      }
      if (!self::wordpress_is_present() || !self::is_winner($root)) {
        return;
      }
      HostDefaults::on_miss(null);
      self::fill_missing();
    });
  }

  /** Whether $root is the copy whose classes this process loads, and the latest registered one. */
  private static function is_winner(string $root): bool {
    $file = (string) (new \ReflectionClass(HostDefaults::class))->getFileName();
    if (!str_starts_with($file, $root)) {
      return false;
    }
    if (!class_exists('Tangible_DDD_Versions', false)) {
      return true;
    }
    $copies = \Tangible_DDD_Versions::instance()->all_registered();
    $latest = \Tangible_DDD_Versions::instance()->latest();
    return $latest === null || rtrim($copies[$latest], '/') . '/' === $root;
  }

  /**
   * The WordPress surfaces the 0.6.5 constructors and factories touched:
   * the hook API (add_action, has_action, do_action) or the options API
   * (OutboxConfig::from_options read get_option directly).
   */
  private static function wordpress_is_present(): bool {
    return function_exists('add_action') || function_exists('get_option');
  }

  /** @return array<class-string, \Closure(): object> */
  private static function defaults(): array {
    $defaults = [
      IClock::class => static fn (): object => new SystemClock(),
      ITransactionBoundary::class => static fn (): object => new WpdbTransactionBoundary(),
      IProcessLock::class => static fn (): object => new GetLockProcessLock(),
      ISubscriptionRegistry::class => static fn (): object => new WpHookSubscriptionRegistry(),
      IInfrastructureSignalDispatcher::class => static fn (): object => new WpHookSignalDispatcher(),
      ISubscriberProbe::class => static fn (): object => new HasActionSubscriberProbe(),
      IActorProvider::class => static fn (): object => new WpActorProvider(),
      IEnvironmentProvider::class => static fn (): object => new WpEnvironmentProvider(),
      IOutboxOptionsReader::class => static fn (): object => new WpOptionsOutboxConfigReader(),
      IHostPortFactory::class => static fn (): object => new WpHostPortFactory(),
    ];
    if (interface_exists(LoggerInterface::class)) {
      $defaults[LoggerInterface::class] = static fn (): object => new ErrorLogLogger();
    }
    return $defaults;
  }
}
