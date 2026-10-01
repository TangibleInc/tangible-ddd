<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;

/**
 * The persistable source of a post-await step's second argument (wave 4,
 * fix round 1). The runner hands a resumed step `resume_argument`; when that
 * step is re-run in a later wake (a #[RetryStep] retry, or an #[Async]
 * post-await step's continuation) the argument must come from the row, not
 * from the runner's memory. ProcessSteps::$resume holds one of these shapes,
 * plain scalars only, stamped with the step index it belongs to:
 *
 *   ['kind' => 'mechanism', 'class' => <IAwaitMechanism class>, 'data' => to_array(),
 *    'event' => null | ['class' => <IIntegrationEvent class>, 'payload' => integration_payload()]]
 *     → $class::from_array($data)->resume_argument($event)
 *   ['kind' => 'event', 'event' => [...]]          → the event (a PrecheckSatisfied::with($fact))
 *   ['kind' => 'value', 'value' => serialize_polymorphic()]  → a JsonLifecycleValue
 *   ['kind' => 'raw', 'value' => null|scalar|array of scalars]
 *
 * @internal runner machinery
 */
final class ResumeSource {

  public static function ofMechanism(IAwaitMechanism $mechanism, ?IIntegrationEvent $event): ?array {
    $encoded = $event === null ? null : self::encodeEvent($event);
    if ($event !== null && $encoded === null) {
      return null;
    }
    return ['kind' => 'mechanism', 'class' => get_class($mechanism), 'data' => $mechanism->to_array(), 'event' => $encoded];
  }

  /** A precheck's own value; null when it cannot be persisted. */
  public static function ofValue(mixed $value): ?array {
    if ($value instanceof IIntegrationEvent) {
      $encoded = self::encodeEvent($value);
      return $encoded === null ? null : ['kind' => 'event', 'event' => $encoded];
    }
    if ($value instanceof JsonLifecycleValue) {
      return ['kind' => 'value', 'value' => JsonLifecycleValue::serialize_polymorphic($value)];
    }
    if ($value === null || is_scalar($value) || (is_array($value) && self::plain($value))) {
      return ['kind' => 'raw', 'value' => $value];
    }
    return null;
  }

  public static function restore(array $source): mixed {
    $event = isset($source['event']) && is_array($source['event']) ? self::decodeEvent($source['event']) : null;
    return match ($source['kind'] ?? null) {
      'mechanism' => self::mechanism($source)->resume_argument($event),
      'event' => $event,
      'value' => JsonLifecycleValue::deserialize_polymorphic((array) $source['value']),
      'raw' => $source['value'] ?? null,
      default => throw new \UnexpectedValueException('Unknown resume source kind: ' . var_export($source['kind'] ?? null, true)),
    };
  }

  private static function mechanism(array $source): IAwaitMechanism {
    $class = (string) ($source['class'] ?? '');
    if (!is_a($class, IAwaitMechanism::class, true)) {
      throw new \UnexpectedValueException("Resume source names no await mechanism: $class");
    }
    return $class::from_array((array) ($source['data'] ?? []));
  }

  private static function encodeEvent(IIntegrationEvent $event): ?array {
    try {
      return ['class' => get_class($event), 'payload' => $event->integration_payload()];
    } catch (\Throwable) {
      return null; // NonReversibleValue: cannot cross a wake
    }
  }

  private static function decodeEvent(array $encoded): IIntegrationEvent {
    $class = (string) ($encoded['class'] ?? '');
    if (!is_a($class, IIntegrationEvent::class, true)) {
      throw new \UnexpectedValueException("Resume source names no integration event: $class");
    }
    return $class::from_payload((array) ($encoded['payload'] ?? []));
  }

  private static function plain(array $value): bool {
    foreach ($value as $item) {
      if (!($item === null || is_scalar($item) || (is_array($item) && self::plain($item)))) {
        return false;
      }
    }
    return true;
  }
}
