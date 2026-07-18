<?php

namespace TangibleDDD\Testing;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/**
 * Doctrine conformance scanners, shift-left of the runtime enforcers.
 *
 * event_violations(): the ctor IS the wire schema (0.2.0 taxonomy), and
 * strict scalarise()/revive() enforce it — but only at FIRST PUBLISH, which
 * can be weeks after the class was written, on a live box. This scanner
 * applies the same law by reflection at test time. Legal param types mirror
 * the codec exactly: scalars (nullable ok), BackedEnum, date types that can
 * ACCEPT a DateTimeImmutable (revive() always constructs one — a mutable
 * DateTime param would TypeError), and arrays. Unions can't be revived;
 * untyped params have no schema.
 *
 * listener_violations(): a listener is a stateless translation policy —
 * fact in, intention out. Object ctor dependencies are the fat-listener
 * anti-pattern: the work they imply belongs in the command handler, where
 * audit/causation/retry live. Scalar ctor params (config) are tolerated.
 *
 * Both return violation arrays ({class, param, problem}) instead of
 * asserting, so consumer PHPUnit suites AND `wp ddd doctor` can share them:
 *
 *   $this->assertSame([], IntegrationConformance::event_violations(__DIR__ . '/../../src'),
 *     IntegrationConformance::describe(...));
 */
final class IntegrationConformance {

  /** @return list<array{class: string, param: string, problem: string}> */
  public static function event_violations(string $src_dir): array {
    $violations = [];

    foreach (self::classes_in($src_dir) as $class) {
      $ref = new ReflectionClass($class);
      if ($ref->isAbstract() || !self::uses_trait($ref, IntegrationBehaviour::class)) {
        continue;
      }

      foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
        if (null !== $problem = self::schema_problem($param)) {
          $violations[] = ['class' => $class, 'param' => $param->getName(), 'problem' => $problem];
        }
      }
    }

    return $violations;
  }

  /** @return list<array{class: string, param: string, problem: string}> */
  public static function listener_violations(string $src_dir): array {
    $violations = [];

    foreach (self::classes_in($src_dir) as $class) {
      $ref = new ReflectionClass($class);
      if ($ref->isAbstract() || !$ref->isSubclassOf(IntegrationListener::class)) {
        continue;
      }

      $ctor = $ref->getConstructor();
      if ($ctor === null || $ctor->getDeclaringClass()->getName() === IntegrationListener::class) {
        continue; // no own ctor — the happy path
      }

      foreach ($ctor->getParameters() as $param) {
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
          $violations[] = [
            'class' => $class,
            'param' => $param->getName(),
            'problem' => sprintf(
              'object dependency (%s) — a listener only translates fact to intention; '
              . 'the work this dependency implies belongs in the command handler',
              $type->getName(),
            ),
          ];
        }
      }
    }

    return $violations;
  }

  /** One line per violation — ready for an assertion message or doctor output. */
  public static function describe(array $violations): string {
    return implode("\n", array_map(
      static fn (array $v) => sprintf('%s::$%s — %s', $v['class'], $v['param'], $v['problem']),
      $violations,
    ));
  }

  // ── the codec's law, as reflection ───────────────────────────────────

  private static function schema_problem(ReflectionParameter $param): ?string {
    $type = $param->getType();

    if ($type === null) {
      return 'no declared type — the ctor IS the wire schema; a schema-less param cannot round-trip';
    }

    if (!$type instanceof ReflectionNamedType) {
      return 'union/intersection type — revive() only coerces named types, this would pass through raw';
    }

    if ($type->isBuiltin()) {
      return in_array($type->getName(), ['int', 'float', 'string', 'bool', 'array'], true)
        ? null
        : sprintf('builtin "%s" is not publishable — the codec knows scalars and arrays only', $type->getName());
    }

    $t = $type->getName();

    if (is_a($t, BackedEnum::class, true)) {
      return null;
    }

    if (is_a($t, DateTimeInterface::class, true)) {
      // revive() always constructs a DateTimeImmutable; the declared type must accept one.
      return is_a(DateTimeImmutable::class, $t, true)
        ? null
        : sprintf('%s cannot hold the DateTimeImmutable revive() constructs — declare DateTimeImmutable (or the interface)', $t);
    }

    return sprintf(
      'entity/object param (%s) throws NonReversibleValue at first publish — '
      . 'carry the id and let the consumer rehydrate',
      $t,
    );
  }

  // ── discovery ─────────────────────────────────────────────────────────

  /** @return list<class-string> every class declared under $src_dir */
  private static function classes_in(string $src_dir): array {
    $real = realpath($src_dir);
    if ($real === false) {
      return [];
    }

    $classes = [];
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $declared = self::declared_in($file->getPathname());

      // PSR-4 covers one-class-per-file; a file declaring several classes
      // (fixtures, grouped fakes) needs loading directly. The token scan
      // already proved it declares classes, so requiring it is safe.
      if (array_filter($declared, static fn (string $c) => !class_exists($c))) {
        require_once $file->getPathname();
      }

      foreach ($declared as $class) {
        if (class_exists($class, false) || class_exists($class)) {
          $classes[] = $class;
        }
      }
    }

    return $classes;
  }

  /** @return list<string> FQCNs declared in one file (token scan, no execution) */
  private static function declared_in(string $path): array {
    $tokens = token_get_all((string) file_get_contents($path));
    $namespace = '';
    $classes = [];

    foreach ($tokens as $i => $token) {
      if (!is_array($token)) {
        continue;
      }

      if ($token[0] === T_NAMESPACE) {
        for ($j = $i + 1; $j < count($tokens); $j++) {
          if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
            $namespace = $tokens[$j][1];
            break;
          }
          if ($tokens[$j] === ';' || $tokens[$j] === '{') {
            break;
          }
        }
      }

      if ($token[0] === T_CLASS) {
        // skip ::class resolutions and anonymous classes
        $prev = self::prev_meaningful($tokens, $i);
        if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
          continue;
        }
        for ($j = $i + 1; $j < count($tokens); $j++) {
          if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            $classes[] = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$j][1];
            break;
          }
          if (!is_array($tokens[$j]) && trim((string) $tokens[$j]) !== '') {
            break; // anonymous class or syntax we don't scan
          }
        }
      }
    }

    return $classes;
  }

  private static function prev_meaningful(array $tokens, int $i): mixed {
    for ($j = $i - 1; $j >= 0; $j--) {
      if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        return $tokens[$j];
      }
    }
    return null;
  }

  private static function uses_trait(ReflectionClass $ref, string $trait): bool {
    foreach (self::all_traits($ref) as $used) {
      if ($used === $trait) {
        return true;
      }
    }
    return false;
  }

  /** Traits of the class, its ancestors, and traits-of-traits. */
  private static function all_traits(ReflectionClass $ref): array {
    $traits = [];
    $current = $ref;
    while ($current !== false) {
      foreach ($current->getTraitNames() as $name) {
        $traits = array_merge($traits, [$name], self::all_traits(new ReflectionClass($name)));
      }
      $current = $current->getParentClass();
    }
    return array_unique($traits);
  }
}
