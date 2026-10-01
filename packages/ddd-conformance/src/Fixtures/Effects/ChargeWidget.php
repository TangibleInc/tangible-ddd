<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Effects;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;

/**
 * D1 ExternalEffect (effect.journal-reuse): charges a widget at an
 * external system. perform() is the external call (counted in
 * EffectLedger::$performs; the n-th call returns ref `ch-{widget}-{n}`);
 * record() commits `charged:{widget}:{ref}` inside the transaction,
 * idempotently, unless EffectLedger::failRecord() armed a failure.
 */
final class ChargeWidget implements IExternalEffectCommand {

  public function __construct(public readonly string $widget_id) {}

  public static function keyFor(string $widgetId): string {
    return "charge:$widgetId";
  }

  public function idempotencyKey(): string {
    return self::keyFor($this->widget_id);
  }

  public function perform(): EffectResult {
    $n = (EffectLedger::$performs[$this->widget_id] ?? 0) + 1;
    EffectLedger::$performs[$this->widget_id] = $n;
    $ref = "ch-{$this->widget_id}-$n";
    return new EffectResult(['charge' => $ref, 'widget' => $this->widget_id], $ref);
  }

  public function record(EffectResult $r): void {
    if ((EffectLedger::$recordFailures[$this->widget_id] ?? 0) > 0) {
      EffectLedger::$recordFailures[$this->widget_id]--;
      throw new \RuntimeException("record of {$this->widget_id} failed");
    }
    $row = "charged:{$this->widget_id}:{$r->externalRef}";
    if (!EffectLedger::rows()->has($row)) {
      EffectLedger::rows()->insert($row, (string) $r->externalRef);
    }
  }

  public function failureCommand(\Throwable $last): ?ICommand {
    return new ChargeFailed($this->widget_id, $last->getMessage());
  }

  public function send(): mixed {
    return EffectLedger::bus()->handle($this);
  }
}
