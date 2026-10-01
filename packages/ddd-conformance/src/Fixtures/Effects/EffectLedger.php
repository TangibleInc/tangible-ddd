<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Effects;

use League\Tactician\CommandBus;
use TangibleDDD\Conformance\ScenarioRows;

/**
 * What the effect.journal-reuse fixtures did in this php process, and the
 * host bus their send() goes through (the scenario sets it from
 * EffectHost::effect_bus()).
 *
 * - performs: perform() calls per widget (the external system's side);
 * - fail_record(): make record() throw for the next $times calls of a widget;
 * - failure_sends: the command ids ChargeFailed was sent under.
 *
 * Committed effects are scenario rows on the host connection:
 * `charged:{widget}:{external ref}` (record()) and `charge-failed:{widget}`
 * (the failure command's handler).
 */
final class EffectLedger {

  public static ?CommandBus $bus = null;

  public static ?ScenarioRows $rows = null;

  /** @var array<string, int> */
  public static array $performs = [];

  /** @var array<string, int> */
  public static array $record_failures = [];

  /** @var list<?string> */
  public static array $failure_sends = [];

  public static function reset(): void {
    self::$bus = null;
    self::$rows = null;
    self::$performs = [];
    self::$record_failures = [];
    self::$failure_sends = [];
  }

  public static function fail_record(string $widgetId, int $times): void {
    self::$record_failures[$widgetId] = $times;
  }

  public static function bus(): CommandBus {
    return self::$bus ?? throw new \LogicException('EffectLedger::$bus is not set (EffectHost::effectBus())');
  }

  public static function rows(): ScenarioRows {
    return self::$rows ?? throw new \LogicException('EffectLedger::$rows is not set');
  }
}
