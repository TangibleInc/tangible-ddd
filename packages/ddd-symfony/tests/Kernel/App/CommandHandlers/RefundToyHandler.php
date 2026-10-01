<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers;

use Doctrine\DBAL\Connection;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectCommand;
use TangibleDDD\Runtime\Effects\IExternalEffectHandler;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RefundToyCommand;

/**
 * E1: performs RefundToyCommand against a pretend provider and records it in
 * app_listener_runs (inside the command's transaction). A service with
 * dependencies, found by the app's resource loading and autoconfigured into
 * the command handler locator.
 *
 * @implements IExternalEffectHandler<RefundToyCommand>
 */
final class RefundToyHandler implements IExternalEffectHandler {

  /** @var list<string> */
  public static array $performed = [];

  public static int $failRecords = 0;

  public function __construct(private readonly Connection $connection) {}

  public function perform(IEffectCommand $command): EffectResult {
    assert($command instanceof RefundToyCommand);
    self::$performed[] = $command->charge_id;
    return new EffectResult(['amount' => $command->amount], 're_' . $command->charge_id);
  }

  public function record(IEffectCommand $command, EffectResult $result): void {
    assert($command instanceof RefundToyCommand);
    $this->connection->insert('app_listener_runs', ['listener' => 'toy-refund', 'widget_id' => $command->charge_id, 'cause_id' => $result->external_ref]);
    if (self::$failRecords > 0) {
      self::$failRecords--;
      throw new \RuntimeException('recording the refund failed (simulated)');
    }
  }
}
