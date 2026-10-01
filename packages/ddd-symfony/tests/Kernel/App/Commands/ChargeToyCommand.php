<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyCharged;
use TangibleDDD\Symfony\Tests\Kernel\App\Toy\ToyPaymentGateway;

/**
 * Reference scenario, D1: an external effect inside a process step. The key
 * is the step's ref (LongProcess::step_ref('charge')), the same on a re-run
 * of the step, so a retried step finds the journaled result and does not
 * charge again. record() runs inside the command's transaction and announces
 * ToyCharged there (the act-level event lane of a self-handling command).
 */
final class ChargeToyCommand extends SelfHandlingCommand implements IExternalEffectCommand {

  public function __construct(
    public readonly string $team_id,
    public readonly int $amount,
    public readonly string $key,
  ) {}

  public function idempotency_key(): string {
    return $this->key;
  }

  public function perform(): EffectResult {
    return new EffectResult(['amount' => $this->amount], ToyPaymentGateway::charge($this->key, $this->team_id, $this->amount));
  }

  public function record(EffectResult $r): void {
    $this->event(new ToyCharged($this->team_id, (string) $r->external_ref));
    if (ToyPaymentGateway::$failRecords > 0) {
      ToyPaymentGateway::$failRecords--;
      throw new \RuntimeException('recording the charge failed (simulated)');
    }
  }

  public function failure_command(\Throwable $last): ?ICommand {
    return null; // inside a process step the step's retry policy, then compensation, govern (register 3.8)
  }

  protected function handle(): void {
    throw new \LogicException('ChargeToyCommand runs through EffectMiddleware (perform + RecordEffect), never its own handle().');
  }
}
