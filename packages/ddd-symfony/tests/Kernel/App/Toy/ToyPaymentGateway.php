<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Toy;

/**
 * Reference scenario: the "external system" of the D1 effect (Stripe's
 * role). It counts real performs and can make the next record() calls fail,
 * to show that a step retry reuses the journaled result.
 */
final class ToyPaymentGateway {

  /** @var list<string> idempotency keys of real calls */
  public static array $performed = [];
  public static int $failRecords = 0;

  public static function reset(int $failRecords = 0): void {
    self::$performed = [];
    self::$failRecords = $failRecords;
  }

  public static function charge(string $idempotencyKey, string $teamId, int $amount): string {
    self::$performed[] = $idempotencyKey;
    return 'ch_' . substr(sha1($idempotencyKey . $teamId . $amount), 0, 12);
  }
}
