<?php

declare(strict_types=1);

/**
 * Wave-5 fixtures of the DurableRuntime cases (consumer `pdocompose`, see
 * Fixtures.php): a handler-class effect (E1) and a behaviour config type
 * registered at include time (W2). The parked answer (AW2) reuses the wave-4
 * KeyedJobSaga.
 */

namespace TangibleDDD\Core\Tests\Pdo\Compose;

require_once __DIR__ . '/Wave4Fixtures.php';

use stdClass;
use TangibleDDD\Application\Commands\Command;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectCommand;
use TangibleDDD\Runtime\Effects\IExternalEffectHandler;

// ── E1: a handler-class effect ─────────────────────────────────────────────

/** The effect as data: refund a charge at the fake provider. */
final class RefundCharge extends Command implements IEffectCommand {
  public function __construct(public readonly string $charge_id) {}

  public function idempotency_key(): string {
    return "provider:refund:{$this->charge_id}";
  }

  public function failure_command(\Throwable $last): ?ICommand {
    return null;
  }
}

/** @implements IExternalEffectHandler<RefundCharge> */
final class RefundChargeHandler implements IExternalEffectHandler {
  /** @var list<string> */
  public array $performed = [];
  /** @var list<string> */
  public array $recorded = [];

  public function perform(IEffectCommand $command): EffectResult {
    assert($command instanceof RefundCharge);
    $this->performed[] = $command->charge_id;
    return new EffectResult(['refund' => 're_' . $command->charge_id], 're_' . $command->charge_id);
  }

  public function record(IEffectCommand $command, EffectResult $result): void {
    $this->recorded[] = (string) $result->external_ref;
  }
}

// ── W2: a behaviour config type ────────────────────────────────────────────

final class PauseConfig extends BaseBehaviourConfig {
  public function get_behaviour_type(): string {
    return 'pdocompose_pause';
  }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    return new static();
  }
}
