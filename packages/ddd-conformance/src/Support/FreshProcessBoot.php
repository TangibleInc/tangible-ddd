<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\Fixtures\Process\OrderedWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\WidgetOrdered;
use TangibleDDD\Conformance\Fixtures\Process\WidgetPacked;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\ScenarioRows;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\ITransactionBoundary;

/**
 * What every fresh php process of a FreshProcesses host registers at boot,
 * after its normal composition: the conformance effect subscriber, the
 * conformance process wiring, and the journal bound to the scenario table.
 * The parent test then reads the effects back as scenario rows.
 */
final class FreshProcessBoot {

  /** Listener on WidgetRegistered: inserts `effect:{widget_id}` (a second run throws, so a duplicate delivery is visible). */
  public const EFFECT_SUBSCRIBER = 'conformance.fresh-effect';

  /** @return list<array{0: class-string, 1: class-string}> */
  public static function starts(): array {
    return [[OrderedWidgetProcess::class, WidgetOrdered::class]];
  }

  /** @return list<class-string> */
  public static function awaits(): array {
    return [WidgetOrdered::class, WidgetPacked::class, PartArrived::class];
  }

  /**
   * The facts a fresh delivery stage must hydrate, by integration name
   * (OutboxRecord::$event_type).
   *
   * @return array<string, class-string>
   */
  public static function fact_classes(): array {
    $map = [];
    foreach ([WidgetRegistered::class, WidgetOrdered::class, WidgetPacked::class, PartArrived::class] as $class) {
      $map[$class::name()] = $class;
    }
    return $map;
  }

  public static function effect_row(string $widgetId): string {
    return "effect:$widgetId";
  }

  public static function boot(ProcessRunner $runner, ISubscriptionRegistry $subscriptions, ScenarioRows $rows, ?ITransactionBoundary $boundary): void {
    foreach (self::starts() as [$process, $event]) {
      $runner->register_start($process, $event);
    }
    foreach (self::awaits() as $event) {
      $runner->register_event($event);
    }

    $subscriptions->add(new Subscriber(self::EFFECT_SUBSCRIBER, Subscriber::LISTENER, WidgetRegistered::class,
      static function (WidgetRegistered $fact) use ($rows, $boundary): void {
        $write = static fn () => $rows->insert(self::effect_row($fact->widget_id), 'effect');
        $boundary === null || $boundary->is_active() ? $write() : $boundary->run($write);
      }));

    ProcessJournal::bind($rows, $boundary);
  }
}
