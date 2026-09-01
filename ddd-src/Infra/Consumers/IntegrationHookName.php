<?php

declare(strict_types=1);

namespace TangibleDDD\Infra\Consumers;

/**
 * Wire-time resolution of an integration event's hook name.
 *
 * Every registration path — listeners, process ignition, process resume —
 * needs the same rule: an event class whose owning plugin is not registered
 * has no hook to bind to, and binding must be skipped rather than fatal.
 *
 * Listeners and processes wire at `init` priority 3, after every active
 * plugin has registered, so a root that cannot be resolved there belongs to
 * a plugin that is deactivated or absent — not one that is merely late. Such
 * an event can never be published, so there is nothing to listen for.
 *
 * Resolution goes through the class (not the registry) on purpose: consumers
 * that stamp their own prefix() never consult the registry at all, and an
 * existence check would wrongly suppress them.
 *
 * The publish path keeps throwing. Recording an event whose consumer never
 * booted is still a bug, and NoConsumerOwnsClass still says so.
 */
final class IntegrationHookName {

  /** @var array<string, true> event classes already reported, to log once each */
  private static array $noted = [];

  /**
   * @param class-string<\TangibleDDD\Domain\Events\IIntegrationEvent> $event_class
   * @return string|null null when no registered consumer owns $event_class
   */
  public static function resolve(string $event_class): ?string {
    try {
      return $event_class::integration_action();
    } catch (NoConsumerOwnsClass $e) {
      return null;
    }
  }

  /** Note a skipped registration once per event class, so absence stays visible. */
  public static function note_absent(string $event_class, string $ceremony): void {
    if (isset(self::$noted[$event_class])) {
      return;
    }
    self::$noted[$event_class] = true;

    error_log(sprintf(
      '[DDD Integration] %s skipped for "%s": no registered consumer owns it (its plugin is inactive)',
      $ceremony,
      $event_class
    ));
  }

  /** @internal test seam */
  public static function reset(): void {
    self::$noted = [];
  }
}
