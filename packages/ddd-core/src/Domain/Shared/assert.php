<?php

namespace TangibleDDD\Domain\Shared;

use TangibleDDD\Domain\Exceptions\TypeMismatchException;

// Composer `files` entry of tangible/ddd-core (register 1.5) that the WP
// winner's loader also require_once's. With several vendored copies on one
// site the two can be different paths, so the definition is guarded: the
// first copy wins and a second include is a no-op, never a redeclare fatal.
if (!function_exists(__NAMESPACE__ . '\\assert_type')) {

  /**
   * Assert a runtime type (scalar, class, or array-of).
   *
   * - If $target is an array, asserts all items match.
   * - If $type is "string" or "int", checks scalar types.
   * - Otherwise treats $type as a class/interface name and checks instanceof.
   *
   * @throws TypeMismatchException
   */
  function assert_type(mixed $target, string $type, string $msg = ''): void {
    if (is_array($target)) {
      if (empty($target)) return;

      foreach ($target as $item) {
        assert_type($item, $type, $msg);
      }

      return;
    }

    $ok = match ($type) {
      'string' => is_string($target),
      'int' => is_int($target),
      'bool' => is_bool($target),
      'float' => is_float($target),
      default => $target instanceof $type,
    };

    if (!$ok) {
      throw new TypeMismatchException(
        $msg ?: sprintf(
          'Expected type %s, got %s',
          $type,
          is_object($target) ? get_class($target) : gettype($target)
        )
      );
    }
  }
}
