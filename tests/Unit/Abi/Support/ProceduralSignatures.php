<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Abi\Support;

use PhpParser\Node;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Static signature snapshot of the procedural API (register B14): every
 * named function and namespace constant declared in a set of PHP sources,
 * read with nikic/php-parser so a legacy tag's files can be snapshotted
 * without loading them next to the current ones (same FQNs).
 *
 * Shape (stable, JSON-serialisable, sorted by name):
 *
 *   functions: { "<fqn>": { file, byRef, returnType, params: [ { name, type, default, byRef, variadic } ] } }
 *   constants: { "<fqn>": "<printed value>" }
 *
 * Types are printed fully qualified (NameResolver), builtins lowercase,
 * union members sorted; defaults are the printed expression (`null`,
 * `[]`, `30`, `'x'`, `self::X`). Both sides of every comparison come from
 * this one reader, so the printing conventions cancel out.
 */
final class ProceduralSignatures {

  /**
   * @param array<string, string> $sources relative path => PHP source
   * @return array{functions: array<string, array<string, mixed>>, constants: array<string, string>}
   */
  public static function fromSources(array $sources): array {
    $parser = (new ParserFactory())->createForHostVersion();
    $printer = new Standard();
    $finder = new NodeFinder();
    $functions = $constants = [];

    ksort($sources);
    foreach ($sources as $file => $code) {
      $ast = $parser->parse($code) ?? [];
      $traverser = new NodeTraverser(new NameResolver());
      $ast = $traverser->traverse($ast);

      /** @var list<Function_> $fns */
      $fns = $finder->findInstanceOf($ast, Function_::class);
      foreach ($fns as $fn) {
        $name = $fn->namespacedName?->toString() ?? $fn->name->toString();
        $functions[$name] = [
          'file' => $file,
          'byRef' => $fn->byRef,
          'returnType' => self::type($fn->returnType),
          'params' => array_map(static fn (Node\Param $p) => [
            'name' => $p->var instanceof Node\Expr\Variable ? (string) $p->var->name : '?',
            'type' => self::type($p->type),
            'default' => $p->default !== null ? $printer->prettyPrintExpr($p->default) : null,
            'byRef' => $p->byRef,
            'variadic' => $p->variadic,
          ], $fn->params),
        ];
      }

      /** @var list<Const_> $consts */
      $consts = $finder->findInstanceOf($ast, Const_::class);
      foreach ($consts as $stmt) {
        foreach ($stmt->consts as $c) {
          $constants[$c->namespacedName?->toString() ?? $c->name->toString()] = $printer->prettyPrintExpr($c->value);
        }
      }
    }

    ksort($functions);
    ksort($constants);
    return ['functions' => $functions, 'constants' => $constants];
  }

  /**
   * @param string $root directory
   * @return array<string, string> relative path => source, every *.php below $root
   */
  public static function readTree(string $root): array {
    $out = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $out[substr($file->getPathname(), strlen(rtrim($root, '/')) + 1)] = (string) file_get_contents($file->getPathname());
      }
    }
    return $out;
  }

  /**
   * B14 compatibility of one function: $now may only append OPTIONAL
   * parameters to $then; every parameter $then had keeps its name (named
   * arguments), type, by-ref, variadic flag and default (a parameter that
   * had none may gain one); the return type and by-ref return are equal.
   *
   * @param array<string, mixed> $then
   * @param array<string, mixed> $now
   * @return list<string> violations
   */
  public static function compatibility(string $fqn, array $then, array $now): array {
    $v = [];
    if ($then['returnType'] !== $now['returnType']) {
      $v[] = sprintf('%s: return type %s became %s', $fqn, $then['returnType'] ?? '(none)', $now['returnType'] ?? '(none)');
    }
    if ($then['byRef'] !== $now['byRef']) {
      $v[] = "$fqn: by-reference return changed";
    }
    foreach ($then['params'] as $i => $p) {
      $q = $now['params'][$i] ?? null;
      if ($q === null) {
        $v[] = sprintf('%s: parameter #%d $%s was removed', $fqn, $i, $p['name']);
        continue;
      }
      foreach (['name', 'type', 'byRef', 'variadic'] as $k) {
        if ($p[$k] !== $q[$k]) {
          $v[] = sprintf('%s: parameter #%d %s %s became %s', $fqn, $i, $k, var_export($p[$k], true), var_export($q[$k], true));
        }
      }
      if ($p['default'] !== null && $p['default'] !== $q['default']) {
        $v[] = sprintf('%s: parameter #%d $%s default %s became %s', $fqn, $i, $p['name'], $p['default'], $q['default'] ?? '(required)');
      }
    }
    foreach (array_slice($now['params'], count($then['params'])) as $j => $q) {
      if ($q['default'] === null && !$q['variadic']) {
        $v[] = sprintf('%s: new parameter #%d $%s is required', $fqn, count($then['params']) + $j, $q['name']);
      }
    }
    return $v;
  }

  private static function type(?Node $t): ?string {
    return match (true) {
      $t === null => null,
      $t instanceof Node\Identifier => strtolower($t->toString()),
      $t instanceof Node\Name => $t->toString(),
      $t instanceof Node\NullableType => '?' . self::type($t->type),
      $t instanceof Node\UnionType => self::members('|', $t->types),
      $t instanceof Node\IntersectionType => self::members('&', $t->types),
      default => get_class($t),
    };
  }

  /** @param list<Node> $types */
  private static function members(string $glue, array $types): string {
    $names = array_map(static fn (Node $n) => self::type($n), $types);
    sort($names);
    return implode($glue, $names);
  }
}
