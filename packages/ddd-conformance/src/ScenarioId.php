<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use PHPUnit\Framework\Attributes\Group;

/**
 * Reads scenario ids off test methods. A scenario method carries exactly one
 * #[Group] whose value is a catalogue id; its method name is the id with
 * `.` and `-` turned into `_` behind a `test_` prefix, so the id is visible
 * in CI output whether a runner prints groups or names.
 */
final class ScenarioId {

  /** The catalogue id a method is tagged with, or null for a non-scenario method. */
  public static function of(\ReflectionMethod $method): ?string {
    $ids = [];
    foreach ($method->getAttributes(Group::class) as $attr) {
      $name = $attr->newInstance()->name();
      if (ScenarioCatalogue::isKnown($name)) {
        $ids[] = $name;
      }
    }
    if (count($ids) > 1) {
      throw new \LogicException(sprintf('%s::%s carries more than one scenario id', $method->class, $method->name));
    }
    return $ids[0] ?? null;
  }

  public static function methodName(string $id): string {
    return 'test_' . str_replace(['.', '-'], '_', $id);
  }

  /**
   * @param list<class-string> $classes concrete host test classes
   * @return array<string, list<string>> id => "Class::method" implementing it
   */
  public static function implementedBy(array $classes): array {
    $out = [];
    foreach ($classes as $class) {
      $ref = new \ReflectionClass($class);
      if ($ref->isAbstract()) {
        continue;
      }
      foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $id = self::of($m);
        if ($id !== null) {
          $out[$id][] = $ref->getShortName() . '::' . $m->name;
        }
      }
    }
    ksort($out);
    return $out;
  }
}
