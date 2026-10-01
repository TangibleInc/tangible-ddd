<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

/**
 * The rollback consumer, wired the same way by N (RollbackTestCase) and by
 * a 0.6.x winner (bin/legacy.php): one ignition, two awaited facts and one
 * listener, all through calls whose 0.6.2 shape N keeps (R2).
 */
final class RbConsumer {

  public const PREFIX = 'ddd_rb';

  public const NAMESPACE_ROOT = 'TangibleDDD\\Tests\\Compat\\Rollback\\Fixtures';

  /** While this option is truthy, the RbNote listener throws (the delivery-retry fixtures). */
  public const LISTENER_DOWN_OPTION = 'ddd_rb_listener_down';

  /** register_start / register_event on a ProcessRunner of either runtime. */
  public static function wire(object $runner): void {
    $runner->register_start(RbOrderSaga::class, RbOrderPlaced::class);
    $runner->register_event(RbPaymentReceived::class);
    $runner->register_event(RbPartShipped::class);
  }

  /** @param callable(string, callable): void $integrationAction the runtime's integration_action() */
  public static function listen(callable $integrationAction): void {
    $integrationAction(RbNote::class, static function (array $payload): void {
      $text = (string) ($payload['text'] ?? '?');
      $text = strlen($text) > 64 ? 'len' . strlen($text) : $text; // large facts (D6) journal their size
      if (get_option(self::LISTENER_DOWN_OPTION)) {
        RbJournal::mark("note:down:$text");
        throw new \RuntimeException("RbNote listener is down ($text)");
      }
      RbJournal::mark("note:$text");
    });
  }
}
