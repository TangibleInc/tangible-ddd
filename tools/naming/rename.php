<?php
/**
 * House-style rename codemod: applies docs/extraction/naming/table.json (and
 * the private twins of tools/naming/private-twins.json) to the declarations
 * and to every site that resolves to them.
 *
 *   php tools/naming/rename.php [--dry-run] [--report=path.json]
 *   php tools/naming/rename.php --target=/path/to/txp --paths=src,tests,config [--dry-run]
 *
 * What it renames (format-preserving printer, so untouched code keeps its bytes):
 *   - method and property declarations on the table's class and on every
 *     subtype (implementations, overrides, anonymous classes, consumer
 *     classes), promoted constructor properties included;
 *   - method calls (instance, static, nullsafe, first-class callables
 *     `foo(...)`), property fetches (instance, static, nullsafe, inside
 *     interpolated strings), when the receiver's type resolves to a class
 *     whose lineage carries the rename;
 *   - named arguments of `new X(...)`, `new self/static(...)`,
 *     `parent::__construct(...)` and attributes, for renamed promoted
 *     constructor properties only, and the parameter variable inside the
 *     constructor body;
 *   - string method names: array callables `[$x, 'foo']` / `[X::class, 'foo']`,
 *     `method_exists` / `property_exists`, `ReflectionMethod` /
 *     `ReflectionProperty`, PHPUnit doubles' `->method('foo')` (createMock,
 *     createStub, intersection mocks), `getSubscribedEvents()` arrays, and a string label
 *     equal to the enclosing method's old name (`$this->write('retryLater')`);
 *   - comments and docblocks: `X::foo()`, `X::$foo`, `@method`, `@param $foo`
 *     of a renamed promoted property, and `foo()` / `->foo` / `` `foo` ``
 *     mentions when the name resolves (the enclosing class renames it, or the
 *     name is globally unambiguous).
 *
 * Receiver types come from a small flow-insensitive inference per function:
 * `$this`/`self`/`static`/`parent`, native and docblock parameter, property and
 * return types (`list<X>`, `X[]`, `array<k, X>` element types too), `new`,
 * assignments, `foreach`, `catch`, `instanceof`, inline `@var`, closures'
 * `use` and arrow functions' outer scope. Vendor and PHP classes are reflected.
 *
 * Where a receiver cannot be resolved, the site is renamed only when the name
 * is globally unambiguous: every class in the index that declares it renames
 * it to the same target, and no vendor or PHP class declares it. Otherwise the
 * site is listed under "unresolved" and is left for a hand fix.
 */

declare(strict_types=1);

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NameContext;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

$lib_root = dirname(__DIR__, 2);
require $lib_root . '/vendor/autoload.php';

$opts = getopt('', ['dry-run', 'report::', 'table::', 'extra::', 'target::', 'paths::', 'index::', 'vendor::', 'docs::']);
$dry_run = isset($opts['dry-run']);
$target = rtrim($opts['target'] ?? $lib_root, '/');
$is_lib = realpath($target) === realpath($lib_root);

const LIB_WRITE = [
  'packages/ddd-core/src', 'packages/ddd-core/tests', 'packages/ddd-wp/src', 'packages/ddd-wp/wordpress',
  'packages/ddd-symfony/src', 'packages/ddd-symfony/config', 'packages/ddd-symfony/tests',
  'packages/ddd-conformance/src', 'packages/ddd-conformance/tests',
  'tests', 'ddd-wordpress', 'compat', 'loader', 'examples', 'tools/mega-trace',
];
const LIB_INDEX = [
  'packages/ddd-core/src', 'packages/ddd-core/tests', 'packages/ddd-wp/src', 'packages/ddd-wp/wordpress',
  'packages/ddd-symfony/src', 'packages/ddd-symfony/tests', 'packages/ddd-conformance/src',
  'packages/ddd-conformance/tests', 'tests', 'ddd-wordpress', 'compat', 'loader', 'examples', 'tools/mega-trace',
];
const LIB_DOCS = [
  'packages/ddd-symfony/README.md', 'packages/ddd-conformance/README.md', 'examples/symfony/README.md',
  'examples/plain-php-durable/README.md', 'tools/mega-trace/README.md', 'packages/ddd-core/src/Defaults/Pdo/README.md',
];

$csv = fn (string $s): array => array_values(array_filter(array_map('trim', explode(',', $s))));
$abs = fn (string $base, string $p): string => str_starts_with($p, '/') ? $p : "$base/$p";

$write_dirs = isset($opts['paths']) ? array_map(fn ($p) => $abs($target, $p), $csv($opts['paths']))
  : array_map(fn ($p) => "$lib_root/$p", LIB_WRITE);
$index_dirs = array_map(fn ($p) => "$lib_root/$p", LIB_INDEX);
if (isset($opts['index'])) {
  $index_dirs = array_merge($index_dirs, array_map(fn ($p) => $abs($target, $p), $csv($opts['index'])));
}
$index_dirs = array_values(array_unique(array_merge($index_dirs, $write_dirs)));
$doc_files = isset($opts['docs']) ? array_map(fn ($p) => $abs($target, $p), $csv($opts['docs']))
  : ($is_lib ? array_map(fn ($p) => "$lib_root/$p", LIB_DOCS) : []);

// Vendor trees: reflection for external ancestors, and the name scan for the
// globally-unambiguous rule. Copies of the library itself (vendor/tangible)
// are skipped: they carry the old names.
$vendor_dirs = ["$lib_root/vendor", "$lib_root/packages/ddd-symfony/vendor", "$lib_root/packages/ddd-conformance/vendor"];
if (!$is_lib) $vendor_dirs[] = "$target/vendor";
if (isset($opts['vendor'])) $vendor_dirs = array_merge($vendor_dirs, $csv($opts['vendor']));
foreach (array_slice($vendor_dirs, 1) as $vd) {
  if (is_file("$vd/autoload.php")) {
    $loader = require_once "$vd/autoload.php";
  }
}

// ---------------------------------------------------------------- the table

/** @var array<string, array{fqcn: string, kind: string, from: string, to: string}> */
$table = [];
$table_files = [$opts['table'] ?? "$lib_root/docs/extraction/naming/table.json", $opts['extra'] ?? __DIR__ . '/private-twins.json'];
foreach ($table_files as $tf) {
  foreach (json_decode((string) file_get_contents($tf), true, 512, JSON_THROW_ON_ERROR) as $e) {
    $table[strtolower($e['fqcn']) . '|' . $e['kind'] . '|' . $e['from']] = $e;
  }
}
$from_names = ['method' => [], 'property' => []];
foreach ($table as $e) $from_names[$e['kind']][$e['from']] = true;

// ---------------------------------------------------------------- helpers

function php_files(string $dir): array {
  if (is_file($dir)) return [$dir];
  if (!is_dir($dir)) return [];
  $out = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php')) continue;
    if (preg_match('#/(vendor|var|node_modules|\.reference|\.phpunit\.cache)/#', $p)) continue;
    $out[] = $p;
  }
  sort($out);
  return $out;
}

/** Lowercased class key; the original spelling is kept for autoloading (PSR-4 is case-sensitive). */
function lc(string $s): string {
  $s = ltrim($s, '\\');
  $k = strtolower($s);
  $cur = $GLOBALS['class_spelling'][$k] ?? null;
  if ($cur === null || $cur === $k) $GLOBALS['class_spelling'][$k] = $s;
  return $k;
}

function spelling(string $lc): string {
  return $GLOBALS['class_spelling'][$lc] ?? $lc;
}

/** A type: classes (lowercased FQCN => true), element classes of arrays, and whether something unknown is in the union. */
final class Ty {
  public array $classes = [];
  public array $elems = [];
  public bool $unknown = false;

  public static function unknown(): self {
    $t = new self();
    $t->unknown = true;
    return $t;
  }

  public static function none(): self {
    return new self();
  }

  public static function of(string ...$classes): self {
    $t = new self();
    foreach ($classes as $c) $t->classes[lc($c)] = true;
    return $t;
  }

  public function union(self $o): self {
    $t = new self();
    $t->classes = $this->classes + $o->classes;
    $t->elems = $this->elems + $o->elems;
    $t->unknown = $this->unknown || $o->unknown;
    return $t;
  }

  public function elem_type(): self {
    if (!$this->elems) return self::unknown();
    $t = new self();
    $t->classes = $this->elems;
    return $t;
  }

  public function is_empty(): bool {
    return !$this->classes && !$this->elems && !$this->unknown;
  }

  public function key(): string {
    $c = array_keys($this->classes);
    $e = array_keys($this->elems);
    sort($c);
    sort($e);
    return implode(',', $c) . '|' . implode(',', $e) . '|' . (int) $this->unknown;
  }
}

const BUILTIN_TYPES = [
  'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'true', 'false', 'null', 'void', 'never',
  'mixed', 'object', 'array', 'iterable', 'callable', 'resource', 'scalar', 'numeric', 'list', 'non-empty-list',
  'non-empty-array', 'class-string', 'non-empty-string', 'positive-int', 'negative-int', 'non-negative-int',
  'array-key', 'callable-string', 'literal-string', 'numeric-string', 'key-of', 'value-of', 'int-mask',
  'non-falsy-string', 'lowercase-string', 'closed-resource', 'open-resource', 'non-positive-int',
];
const CONTAINER_TYPES = ['list', 'non-empty-list', 'array', 'non-empty-array', 'iterable'];

/** Split a docblock type at top-level `|` / `&`. */
function split_union(string $s): array {
  $out = [];
  $depth = 0;
  $cur = '';
  for ($i = 0, $n = strlen($s); $i < $n; $i++) {
    $ch = $s[$i];
    if ($ch === '<' || $ch === '(' || $ch === '{' || $ch === '[') $depth++;
    if ($ch === '>' || $ch === ')' || $ch === '}' || $ch === ']') $depth--;
    if (($ch === '|' || $ch === '&') && $depth === 0) {
      $out[] = $cur;
      $cur = '';
      continue;
    }
    $cur .= $ch;
  }
  $out[] = $cur;
  return array_values(array_filter(array_map('trim', $out), fn ($x) => $x !== ''));
}

/** Split generic arguments `K, V` at top level. */
function split_args(string $s): array {
  $out = [];
  $depth = 0;
  $cur = '';
  for ($i = 0, $n = strlen($s); $i < $n; $i++) {
    $ch = $s[$i];
    if ($ch === '<' || $ch === '(' || $ch === '{') $depth++;
    if ($ch === '>' || $ch === ')' || $ch === '}') $depth--;
    if ($ch === ',' && $depth === 0) {
      $out[] = $cur;
      $cur = '';
      continue;
    }
    $cur .= $ch;
  }
  $out[] = $cur;
  return array_map('trim', $out);
}

/**
 * Parse a docblock type expression into a Ty.
 *
 * @param callable(string): string $resolve class name resolver
 */
function doc_type(string $s, callable $resolve, ?string $self): Ty {
  $t = Ty::none();
  foreach (split_union($s) as $part) {
    $part = ltrim($part, '?');
    if ($part === '') continue;
    if (str_starts_with($part, '(') && str_ends_with($part, ')')) {
      $t = $t->union(doc_type(substr($part, 1, -1), $resolve, $self));
      continue;
    }
    if (preg_match('/^(?:array|list|non-empty-array|non-empty-list)\s*\{(.*)\}$/s', $part, $m)) {
      // Shape: every value type becomes an element type.
      $e = Ty::none();
      foreach (split_args($m[1]) as $item) {
        $item = preg_replace('/^[\w\'"-]+\??\s*:\s*/', '', $item);
        $v = doc_type((string) $item, $resolve, $self);
        $e->elems += $v->classes;
      }
      $t = $t->union($e);
      continue;
    }
    if (str_ends_with($part, '[]')) {
      $inner = doc_type(substr($part, 0, -2), $resolve, $self);
      $e = Ty::none();
      $e->elems = $inner->classes;
      $t = $t->union($e);
      continue;
    }
    if (preg_match('/^([\\\\\w-]+)\s*<(.*)>$/s', $part, $m)) {
      $base = $m[1];
      $args = split_args($m[2]);
      $last = doc_type((string) end($args), $resolve, $self);
      $e = Ty::none();
      $e->elems = $last->classes;
      if (!in_array(strtolower($base), CONTAINER_TYPES, true) && !in_array(strtolower($base), BUILTIN_TYPES, true)) {
        $e->classes[lc($resolve($base))] = true;
      }
      $t = $t->union($e);
      continue;
    }
    if (preg_match('/^[\\\\A-Za-z_][\\\\\w]*$/', $part)) {
      $low = strtolower($part);
      if (in_array($low, ['self', 'static', '$this'], true)) {
        if ($self !== null) $t->classes[$self] = true;
        continue;
      }
      if (in_array($low, BUILTIN_TYPES, true)) continue;
      $t->classes[lc($resolve($part))] = true;
      continue;
    }
    if ($part === '$this') {
      if ($self !== null) $t->classes[$self] = true;
      continue;
    }
    // Shapes, closures, literals: nothing a method can be called on by name.
  }
  return $t;
}

/** Native type node into a Ty. */
function native_type(?Node $type, ?string $self, ?string $parent): Ty {
  if ($type === null) return Ty::unknown();
  if ($type instanceof Node\NullableType) return native_type($type->type, $self, $parent);
  if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
    $t = Ty::none();
    foreach ($type->types as $x) $t = $t->union(native_type($x, $self, $parent));
    return $t;
  }
  if ($type instanceof Node\Identifier) {
    $n = $type->toLowerString();
    if ($n === 'static' || $n === 'self') return $self !== null ? Ty::of($self) : Ty::unknown();
    if (in_array($n, ['mixed', 'object', 'iterable', 'array'], true)) return Ty::unknown();
    return Ty::none();
  }
  if ($type instanceof Node\Name) {
    $n = strtolower($type->toString());
    if ($n === 'self' || $n === 'static') return $self !== null ? Ty::of($self) : Ty::unknown();
    if ($n === 'parent') return $parent !== null ? Ty::of($parent) : Ty::unknown();
    $r = $type->getAttribute('resolvedName');
    return Ty::of($r instanceof Node\Name ? $r->toString() : $type->toString());
  }
  return Ty::unknown();
}

/** Docblock tags: [tag => [[type, var|null], ...]] */
function doc_tags(?string $doc): array {
  if ($doc === null || $doc === '') return [];
  $out = [];
  if (preg_match_all('/@(var|param|return|property|property-read|psalm-var|phpstan-var|psalm-param|phpstan-param|psalm-return|phpstan-return)\s+((?:[^\s<({]|<(?:[^<>]|<[^<>]*>)*>|\((?:[^()]|\([^()]*\))*\)|\{[^{}]*\})+)(?:\s+(?:\.\.\.)?&?\$(\w+))?/', $doc, $m, PREG_SET_ORDER)) {
    foreach ($m as $x) {
      $tag = preg_replace('/^(psalm|phpstan)-/', '', $x[1]);
      $out[$tag][] = [$x[2], $x[3] ?? null];
    }
  }
  return $out;
}

// ---------------------------------------------------------------- index

final class Index {
  /** @var array<string, array> lc fqcn => class info */
  public array $classes = [];
  /** @var array<string, array<string, true>> kind => name => true, for vendor/PHP declared names */
  public array $vendor_names = ['method' => [], 'property' => []];
  /** @var array<string, Ty> lc function name => return type */
  public array $functions = [];
  public array $table;
  private array $lineage_cache = [];
  private array $rename_cache = [];

  public function __construct(array $table) {
    $this->table = $table;
  }

  /** @return list<string> lc fqcns: self first, then ancestors (index and vendor), transitive. */
  public function lineage(string $lc): array {
    if (isset($this->lineage_cache[$lc])) return $this->lineage_cache[$lc];
    $this->lineage_cache[$lc] = [$lc];
    $out = [$lc];
    $seen = [$lc => true];
    $queue = $this->direct_ancestors($lc);
    while ($queue) {
      $a = array_shift($queue);
      if (isset($seen[$a])) continue;
      $seen[$a] = true;
      $out[] = $a;
      foreach ($this->direct_ancestors($a) as $b) $queue[] = $b;
    }
    return $this->lineage_cache[$lc] = $out;
  }

  public function direct_ancestors(string $lc): array {
    if (isset($this->classes[$lc])) {
      $c = $this->classes[$lc];
      return array_values(array_filter(array_merge([$c['parent']], $c['interfaces'], $c['traits'])));
    }
    $r = self::reflect($lc);
    if ($r === null) return [];
    $out = [];
    if ($r->getParentClass()) $out[] = lc($r->getParentClass()->getName());
    foreach ($r->getInterfaceNames() as $i) $out[] = lc($i);
    foreach ($r->getTraitNames() as $i) $out[] = lc($i);
    return $out;
  }

  public static function reflect(string $lc): ?ReflectionClass {
    static $cache = [];
    if (array_key_exists($lc, $cache)) return $cache[$lc];
    if (str_starts_with($lc, 'tangibleddd\\') || str_contains($lc, '@anonymous')) return $cache[$lc] = null;
    try {
      $name = spelling($lc);
      if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
        return $cache[$lc] = new ReflectionClass($name);
      }
    } catch (\Throwable) {
    }
    return $cache[$lc] = null;
  }

  public function known(string $lc): bool {
    return isset($this->classes[$lc]) || self::reflect($lc) !== null;
  }

  /** The rename target of a member on a class (via its lineage), or null. Throws on a conflict. */
  public function rename_for(string $lc, string $kind, string $name): ?string {
    $key = "$lc|$kind|$name";
    if (array_key_exists($key, $this->rename_cache)) return $this->rename_cache[$key];
    $to = null;
    foreach ($this->lineage($lc) as $a) {
      $e = $this->table["$a|$kind|$name"] ?? null;
      if ($e === null) continue;
      if ($to !== null && $to !== $e['to']) {
        throw new RuntimeException("conflicting renames for $kind $name on $lc: $to vs {$e['to']}");
      }
      $to = $e['to'];
    }
    return $this->rename_cache[$key] = $to;
  }

  /** Does the class (or an ancestor) declare the member? true/false, or null when part of the lineage is unknown. */
  public function declares(string $lc, string $kind, string $name): ?bool {
    $unknown = false;
    foreach ($this->lineage($lc) as $a) {
      if (isset($this->classes[$a])) {
        $c = $this->classes[$a];
        if ($kind === 'method' && isset($c['methods'][strtolower($name)])) return true;
        if ($kind === 'property' && isset($c['props'][$name])) return true;
        continue;
      }
      $r = self::reflect($a);
      if ($r === null) {
        $unknown = true;
        continue;
      }
      if ($kind === 'method' && $r->hasMethod($name)) return true;
      if ($kind === 'property' && $r->hasProperty($name)) return true;
    }
    return $unknown ? null : false;
  }

  /**
   * Status of a member on a receiver class: ['renamed', to] | ['declared'] | ['absent'] | ['unknown'].
   */
  public function status(string $lc, string $kind, string $name): array {
    $to = $this->rename_for($lc, $kind, $name);
    if ($to !== null) return ['renamed', $to];
    $d = $this->declares($lc, $kind, $name);
    if ($d === true) return ['declared'];
    if ($d === false) return ['absent'];
    return ['unknown'];
  }

  /**
   * Method return type on a receiver class. Walks the lineage and keeps
   * looking past a declaration that says nothing about classes (a bare
   * `array` on an implementation inherits the interface's `@return list<X>`).
   */
  public function method_type(string $lc, string $name): ?Ty {
    $first = null;
    foreach ($this->lineage($lc) as $a) {
      if (isset($this->classes[$a])) {
        $m = $this->classes[$a]['methods'][strtolower($name)] ?? null;
        if ($m === null) continue;
        $t = $m['ret'];
        if (isset($t->classes['@static'])) {
          $t = clone $t;
          unset($t->classes['@static']);
          $t->classes[$lc] = true;
        }
      } else {
        $r = self::reflect($a);
        if ($r === null || !$r->hasMethod($name)) continue;
        $t = self::reflection_type($r->getMethod($name)->getReturnType(), $lc);
      }
      if ($t->classes || $t->elems) return $t;
      $first ??= $t;
    }
    return $first;
  }

  public function prop_type(string $lc, string $name): ?Ty {
    $first = null;
    foreach ($this->lineage($lc) as $a) {
      if (isset($this->classes[$a])) {
        $p = $this->classes[$a]['props'][$name] ?? null;
        if ($p === null) continue;
        $t = $p['type'];
      } else {
        $r = self::reflect($a);
        if ($r === null || !$r->hasProperty($name)) continue;
        $t = self::reflection_type($r->getProperty($name)->getType(), $lc);
      }
      if ($t->classes || $t->elems) return $t;
      $first ??= $t;
    }
    return $first;
  }

  public static function reflection_type(?ReflectionType $t, string $self): Ty {
    if ($t === null) return Ty::unknown();
    if ($t instanceof ReflectionNamedType) {
      if ($t->isBuiltin()) {
        return in_array($t->getName(), ['mixed', 'object', 'iterable', 'array'], true) ? Ty::unknown() : Ty::none();
      }
      $n = strtolower($t->getName());
      return Ty::of(($n === 'self' || $n === 'static') ? $self : $t->getName());
    }
    if ($t instanceof ReflectionUnionType || $t instanceof ReflectionIntersectionType) {
      $out = Ty::none();
      foreach ($t->getTypes() as $x) $out = $out->union(self::reflection_type($x, $self));
      return $out;
    }
    return Ty::unknown();
  }

  /** Constructor's declaring class and its promoted parameter names. */
  public function ctor_promoted(string $lc): array {
    foreach ($this->lineage($lc) as $a) {
      if (!isset($this->classes[$a])) continue;
      if (isset($this->classes[$a]['methods']['__construct'])) {
        return [$a, $this->classes[$a]['promoted']];
      }
    }
    return [null, []];
  }
}

/**
 * First pass over a tree: resolve names (attributes only, so the printer sees
 * no change), record the name context on statements and function-likes for
 * docblock resolution, and give anonymous classes a stable id.
 */
final class PrepareVisitor extends NodeVisitorAbstract {
  private ?NameContext $snapshot = null;

  public function __construct(private NameResolver $resolver, private string $file) {}

  public function enterNode(Node $node) {
    if ($node instanceof Node\Stmt\Namespace_ || $node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse) {
      $this->snapshot = null;
    }
    if ($node instanceof Node\Stmt || $node instanceof Node\FunctionLike) {
      $this->snapshot ??= clone $this->resolver->getNameContext();
      $node->setAttribute('name_ctx', $this->snapshot);
    }
    if ($node instanceof Node\Stmt\Class_ && $node->name === null) {
      $node->setAttribute('anon_id', lc('class@anonymous:' . $this->file . ':' . $node->getStartFilePos()));
    }
    return null;
  }

  public function leaveNode(Node $node) {
    if ($node instanceof Node\Stmt\Namespace_) $this->snapshot = null;
    return null;
  }
}

function prepare(array $ast, string $file): array {
  $resolver = new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]);
  (new NodeTraverser($resolver, new PrepareVisitor($resolver, $file)))->traverse($ast);
  return $ast;
}

function empty_ctx(): NameContext {
  $ctx = new NameContext(new \PhpParser\ErrorHandler\Throwing());
  $ctx->startNamespace();
  return $ctx;
}

/** Collects class-likes into the index. */
final class IndexVisitor extends NodeVisitorAbstract {
  public function __construct(private Index $index, private string $file) {}

  public function enterNode(Node $node) {
    if ($node instanceof Node\Stmt\Function_ && isset($node->namespacedName)) {
      $ctx = $node->getAttribute('name_ctx') ?? empty_ctx();
      $ret = native_type($node->returnType, null, null);
      foreach (doc_tags($node->getDocComment()?->getText())['return'] ?? [] as [$ty]) {
        $ret = merge_doc($ret, doc_type($ty, fn (string $n) => resolve_doc_name($n, $ctx), null));
      }
      $this->index->functions[lc($node->namespacedName->toString())] = $ret;
      return null;
    }
    if (!$node instanceof Node\Stmt\ClassLike) return null;
    $id = class_id($node, $this->file);
    if ($id === null) return null;
    $ctx = $node->getAttribute('name_ctx') ?? empty_ctx();
    $resolve = fn (string $n) => resolve_doc_name($n, $ctx);
    $parent = null;
    $ifaces = [];
    if ($node instanceof Node\Stmt\Class_) {
      if ($node->extends) $parent = lc(resolved($node->extends));
      foreach ($node->implements as $i) $ifaces[] = lc(resolved($i));
    } elseif ($node instanceof Node\Stmt\Interface_) {
      foreach ($node->extends as $i) $ifaces[] = lc(resolved($i));
    } elseif ($node instanceof Node\Stmt\Enum_) {
      foreach ($node->implements as $i) $ifaces[] = lc(resolved($i));
    }
    $traits = [];
    $methods = [];
    $props = [];
    $promoted = [];
    foreach ($node->stmts as $stmt) {
      if ($stmt instanceof Node\Stmt\TraitUse) {
        foreach ($stmt->traits as $t) $traits[] = lc(resolved($t));
      }
      if ($stmt instanceof Node\Stmt\ClassMethod) {
        $tags = doc_tags($stmt->getDocComment()?->getText());
        $ret = native_type($stmt->returnType, $id, $parent);
        if ($stmt->returnType instanceof Node\Name && strtolower($stmt->returnType->toString()) === 'static') {
          $ret = Ty::none();
          $ret->classes['@static'] = true;
        }
        if ($stmt->returnType instanceof Node\Identifier && $stmt->returnType->toLowerString() === 'static') {
          $ret = Ty::none();
          $ret->classes['@static'] = true;
        }
        foreach ($tags['return'] ?? [] as [$ty]) {
          $ret = merge_doc($ret, doc_type($ty, $resolve, $id));
        }
        $methods[$stmt->name->toLowerString()] = ['name' => $stmt->name->toString(), 'ret' => $ret, 'static' => $stmt->isStatic()];
        if ($stmt->name->toLowerString() === '__construct') {
          foreach ($stmt->params as $param) {
            if ($param->flags === 0 || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) continue;
            $pn = $param->var->name;
            $t = native_type($param->type, $id, $parent);
            foreach ($tags['param'] ?? [] as [$ty, $var]) {
              if ($var === $pn) $t = merge_doc($t, doc_type($ty, $resolve, $id));
            }
            foreach (doc_tags($param->getDocComment()?->getText())['var'] ?? [] as [$ty]) {
              $t = merge_doc($t, doc_type($ty, $resolve, $id));
            }
            $props[$pn] = ['type' => $t];
            $promoted[$pn] = true;
          }
        }
      }
      if ($stmt instanceof Node\Stmt\Property) {
        $t = native_type($stmt->type, $id, $parent);
        foreach (doc_tags($stmt->getDocComment()?->getText())['var'] ?? [] as [$ty]) {
          $t = merge_doc($t, doc_type($ty, $resolve, $id));
        }
        foreach ($stmt->props as $p) $props[$p->name->toString()] = ['type' => $t];
      }
    }
    foreach (doc_tags($node->getDocComment()?->getText())['property'] ?? [] as [$ty, $var]) {
      if ($var !== null) $props[$var] ??= ['type' => doc_type($ty, $resolve, $id)];
    }
    $this->index->classes[$id] = [
      'name' => $id, 'file' => $this->file, 'parent' => $parent, 'interfaces' => $ifaces, 'traits' => $traits,
      'methods' => $methods, 'props' => $props, 'promoted' => $promoted,
    ];
    return null;
  }
}

/** Prefer the docblock where it says more (classes or element types) than the native type. */
function merge_doc(Ty $native, Ty $doc): Ty {
  if ($doc->is_empty()) return $native;
  if ($native->unknown || !$native->classes) return $doc;
  // Native names a class; the doc may refine it (generics) or add elements.
  return $native->union($doc)->union(Ty::none());
}

function resolved(Node\Name $n): string {
  $r = $n->getAttribute('resolvedName');
  return $r instanceof Node\Name ? $r->toString() : $n->toString();
}

function resolve_doc_name(string $n, NameContext $ctx): string {
  if (str_starts_with($n, '\\')) return ltrim($n, '\\');
  try {
    return $ctx->getResolvedClassName(new Node\Name($n))->toString();
  } catch (\Throwable) {
    return $n;
  }
}

function class_id(Node\Stmt\ClassLike $node, string $file): ?string {
  if ($node->getAttribute('anon_id') !== null) return $node->getAttribute('anon_id');
  if (isset($node->namespacedName)) return lc($node->namespacedName->toString());
  return null;
}

// ---------------------------------------------------------------- inference

final class Scope {
  /** @var array<string, Ty> */
  public array $vars = [];

  public function __construct(public ?string $class, public ?string $parent) {}
}

final class Infer {
  public function __construct(private Index $index) {}

  public function expr(?Node $e, Scope $s, int $depth = 0): Ty {
    if ($e === null || $depth > 40) return Ty::unknown();
    $d = $depth + 1;
    switch (true) {
      case $e instanceof Node\Expr\Variable:
        if ($e->name === 'this') return $s->class !== null ? Ty::of($s->class) : Ty::unknown();
        if (is_string($e->name)) return $s->vars[$e->name] ?? Ty::unknown();
        return Ty::unknown();
      case $e instanceof Node\Expr\New_:
        if ($e->class instanceof Node\Stmt\Class_) {
          return $e->class->getAttribute('anon_id') !== null ? Ty::of($e->class->getAttribute('anon_id')) : Ty::unknown();
        }
        if ($e->class instanceof Node\Name) return $this->class_name($e->class, $s);
        return Ty::unknown();
      case $e instanceof Node\Expr\MethodCall:
      case $e instanceof Node\Expr\NullsafeMethodCall:
        if (!$e->name instanceof Node\Identifier) return Ty::unknown();
        $name = $e->name->toString();
        if (in_array(strtolower($name), ['createmock', 'createstub', 'createconfiguredmock', 'createpartialmock'], true)
          && isset($e->args[0]) && $e->args[0] instanceof Node\Arg) {
          $c = $this->class_const($e->args[0]->value, $s);
          if ($c !== null) return Ty::of($c);
        }
        if (in_array(strtolower($name), ['createmockforintersectionofinterfaces', 'createstubforintersectionofinterfaces'], true)
          && isset($e->args[0]) && $e->args[0] instanceof Node\Arg && $e->args[0]->value instanceof Node\Expr\Array_) {
          $t = Ty::none();
          foreach ($e->args[0]->value->items as $it) {
            $c = $it ? $this->class_const($it->value, $s) : null;
            if ($c !== null) $t->classes[$c] = true;
          }
          if ($t->classes) return $t;
        }
        return $this->call_type($this->expr($e->var, $s, $d), $name);
      case $e instanceof Node\Expr\StaticCall:
        if (!$e->name instanceof Node\Identifier) return Ty::unknown();
        $recv = $e->class instanceof Node\Name ? $this->class_name($e->class, $s) : $this->expr($e->class, $s, $d);
        return $this->call_type($recv, $e->name->toString());
      case $e instanceof Node\Expr\PropertyFetch:
      case $e instanceof Node\Expr\NullsafePropertyFetch:
        if (!$e->name instanceof Node\Identifier) return Ty::unknown();
        return $this->prop_type($this->expr($e->var, $s, $d), $e->name->toString());
      case $e instanceof Node\Expr\StaticPropertyFetch:
        if (!$e->name instanceof Node\VarLikeIdentifier) return Ty::unknown();
        $recv = $e->class instanceof Node\Name ? $this->class_name($e->class, $s) : $this->expr($e->class, $s, $d);
        return $this->prop_type($recv, $e->name->toString());
      case $e instanceof Node\Expr\ArrayDimFetch:
        return $this->expr($e->var, $s, $d)->elem_type();
      case $e instanceof Node\Expr\Ternary:
        return ($e->if ? $this->expr($e->if, $s, $d) : $this->expr($e->cond, $s, $d))->union($this->expr($e->else, $s, $d));
      case $e instanceof Node\Expr\BinaryOp\Coalesce:
        return $this->expr($e->left, $s, $d)->union($this->expr($e->right, $s, $d));
      case $e instanceof Node\Expr\Assign:
      case $e instanceof Node\Expr\AssignRef:
        return $this->expr($e->expr, $s, $d);
      case $e instanceof Node\Expr\Clone_:
        return $this->expr($e->expr, $s, $d);
      case $e instanceof Node\Expr\Match_:
        $t = Ty::none();
        foreach ($e->arms as $arm) $t = $t->union($this->expr($arm->body, $s, $d));
        return $t;
      case $e instanceof Node\Expr\Closure:
      case $e instanceof Node\Expr\ArrowFunction:
        return Ty::of('Closure');
      case $e instanceof Node\Expr\Array_:
        $t = Ty::none();
        foreach ($e->items as $it) {
          if ($it === null) continue;
          $v = $this->expr($it->value, $s, $d);
          $t->elems += $v->classes;
        }
        return $t;
      case $e instanceof Node\Expr\FuncCall:
        if ($e->name instanceof Node\Name) {
          $fn = strtolower($e->name->toString());
          if (in_array($fn, ['array_values', 'array_filter', 'array_reverse', 'array_slice', 'array_merge'], true)
            && isset($e->args[0]) && $e->args[0] instanceof Node\Arg) {
            $t = Ty::none();
            $t->elems = $this->expr($e->args[0]->value, $s, $d)->elems;
            return $t->elems ? $t : Ty::unknown();
          }
          if (in_array($fn, ['end', 'reset', 'current', 'array_pop', 'array_shift', 'array_first', 'array_last'], true)
            && isset($e->args[0]) && $e->args[0] instanceof Node\Arg) {
            return $this->expr($e->args[0]->value, $s, $d)->elem_type();
          }
          // A function declared in the index (namespaced first, then global).
          $ns = $e->name->getAttribute('namespacedName');
          foreach ([$ns instanceof Node\Name ? $ns->toString() : null, resolved($e->name)] as $cand) {
            if ($cand !== null && isset($this->index->functions[lc($cand)])) return $this->index->functions[lc($cand)];
          }
        }
        return Ty::unknown();
      case $e instanceof Node\Scalar:
      case $e instanceof Node\Expr\BinaryOp:
      case $e instanceof Node\Expr\BooleanNot:
      case $e instanceof Node\Expr\Instanceof_:
      case $e instanceof Node\Expr\Cast:
      case $e instanceof Node\Expr\Isset_:
      case $e instanceof Node\Expr\Empty_:
      case $e instanceof Node\Expr\ClassConstFetch && $e->name instanceof Node\Identifier && $e->name->toLowerString() === 'class':
        return Ty::none();
    }
    return Ty::unknown();
  }

  public function class_name(Node\Name $n, Scope $s): Ty {
    $low = strtolower($n->toString());
    if ($low === 'self' || $low === 'static') return $s->class !== null ? Ty::of($s->class) : Ty::unknown();
    if ($low === 'parent') return $s->parent !== null ? Ty::of($s->parent) : Ty::unknown();
    return Ty::of(resolved($n));
  }

  /** `X::class` → lc fqcn, else null. */
  public function class_const(Node $e, Scope $s): ?string {
    if ($e instanceof Node\Expr\ClassConstFetch && $e->name instanceof Node\Identifier
      && $e->name->toLowerString() === 'class' && $e->class instanceof Node\Name) {
      $t = $this->class_name($e->class, $s);
      return array_key_first($t->classes);
    }
    if ($e instanceof Node\Scalar\String_ && preg_match('/^\\\\?[A-Z][\w\\\\]*$/', $e->value) && str_contains($e->value, '\\')) {
      return lc($e->value);
    }
    return null;
  }

  private function call_type(Ty $recv, string $name): Ty {
    if ($recv->unknown && !$recv->classes) return Ty::unknown();
    $t = Ty::none();
    $found = false;
    foreach (array_keys($recv->classes) as $c) {
      $r = $this->index->method_type($c, $name);
      if ($r === null) continue;
      $found = true;
      $t = $t->union($r);
    }
    return $found ? $t : Ty::unknown();
  }

  private function prop_type(Ty $recv, string $name): Ty {
    if ($recv->unknown && !$recv->classes) return Ty::unknown();
    $t = Ty::none();
    $found = false;
    foreach (array_keys($recv->classes) as $c) {
      $r = $this->index->prop_type($c, $name);
      if ($r === null) continue;
      $found = true;
      $t = $t->union($r);
    }
    return $found ? $t : Ty::unknown();
  }

  /**
   * Variable types of one function-like body (flow-insensitive, three passes).
   *
   * @param list<Node> $stmts
   */
  public function scope_for(array $stmts, Scope $s, NameContext $ctx, array $params = [], ?string $doc = null): Scope {
    $resolve = fn (string $n) => resolve_doc_name($n, $ctx);
    $tags = doc_tags($doc);
    foreach ($params as $p) {
      if (!$p->var instanceof Node\Expr\Variable || !is_string($p->var->name)) continue;
      $t = native_type($p->type, $s->class, $s->parent);
      if ($p->variadic) {
        $e = Ty::none();
        $e->elems = $t->classes;
        $t = $e;
      }
      foreach ($tags['param'] ?? [] as [$ty, $var]) {
        if ($var === $p->var->name) $t = merge_doc($t, doc_type($ty, $resolve, $s->class));
      }
      $s->vars[$p->var->name] = $t;
    }
    for ($pass = 0; $pass < 3; $pass++) {
      $this->collect($stmts, $s, $ctx);
    }
    return $s;
  }

  private function assign(Scope $s, string $name, Ty $t): void {
    $cur = $s->vars[$name] ?? null;
    $s->vars[$name] = $cur === null ? $t : $cur->union($t);
  }

  private function collect(array $nodes, Scope $s, NameContext $ctx): void {
    $resolve = fn (string $n) => resolve_doc_name($n, $ctx);
    $stack = $nodes;
    while ($stack) {
      $n = array_pop($stack);
      if (!$n instanceof Node) {
        if (is_array($n)) foreach ($n as $x) $stack[] = $x;
        continue;
      }
      // Inline @var on a statement.
      $doc = $n->getDocComment();
      if ($doc !== null && $n instanceof Node\Stmt) {
        foreach (doc_tags($doc->getText())['var'] ?? [] as [$ty, $var]) {
          $t = doc_type($ty, $resolve, $s->class);
          if ($var !== null) {
            $s->vars[$var] = $t->union($s->vars[$var] ?? Ty::none());
          } elseif ($n instanceof Node\Stmt\Expression && $n->expr instanceof Node\Expr\Assign
            && $n->expr->var instanceof Node\Expr\Variable && is_string($n->expr->var->name)) {
            $s->vars[$n->expr->var->name] = $t->union($s->vars[$n->expr->var->name] ?? Ty::none());
          }
        }
      }
      if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction
        || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassLike) {
        continue;
      }
      // array_map(fn ($x) => ..., $list) and friends: the callback's untyped
      // parameters take the list's element type.
      if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && count($n->args) >= 2
        && $n->args[0] instanceof Node\Arg && $n->args[1] instanceof Node\Arg) {
        $fn = strtolower($n->name->toString());
        [$cb, $list] = $fn === 'array_map' ? [$n->args[0]->value, $n->args[1]->value]
          : (in_array($fn, ['array_filter', 'usort', 'uasort', 'array_walk', 'array_find', 'array_any', 'array_all', 'array_find_key'], true)
            ? [$n->args[1]->value, $n->args[0]->value] : [null, null]);
        if ($cb instanceof Node\Expr\Closure || $cb instanceof Node\Expr\ArrowFunction) {
          $hint = $this->expr($list, $s)->elem_type();
          if (!$hint->unknown) $cb->setAttribute('param_hint', $hint);
        }
      }
      if (($n instanceof Node\Expr\Assign || $n instanceof Node\Expr\AssignRef)) {
        if ($n->var instanceof Node\Expr\Variable && is_string($n->var->name)) {
          $this->assign($s, $n->var->name, $this->expr($n->expr, $s));
        } elseif ($n->var instanceof Node\Expr\List_ || $n->var instanceof Node\Expr\Array_) {
          $elem = $this->expr($n->expr, $s)->elem_type();
          foreach ($n->var->items as $it) {
            if ($it !== null && $it->value instanceof Node\Expr\Variable && is_string($it->value->name)) {
              $this->assign($s, $it->value->name, $elem);
            }
          }
        } elseif ($n->var instanceof Node\Expr\ArrayDimFetch && $n->var->var instanceof Node\Expr\Variable
          && is_string($n->var->var->name)) {
          $v = $this->expr($n->expr, $s);
          $e = Ty::none();
          $e->elems = $v->classes;
          $this->assign($s, $n->var->var->name, $e);
        }
      }
      if ($n instanceof Node\Stmt\Foreach_) {
        $elem = $this->expr($n->expr, $s)->elem_type();
        if ($n->valueVar instanceof Node\Expr\Variable && is_string($n->valueVar->name)) {
          $this->assign($s, $n->valueVar->name, $elem);
        } elseif ($n->valueVar instanceof Node\Expr\List_ || $n->valueVar instanceof Node\Expr\Array_) {
          foreach ($n->valueVar->items as $it) {
            if ($it !== null && $it->value instanceof Node\Expr\Variable && is_string($it->value->name)) {
              $this->assign($s, $it->value->name, Ty::unknown());
            }
          }
        }
        if ($n->keyVar instanceof Node\Expr\Variable && is_string($n->keyVar->name)) {
          $this->assign($s, $n->keyVar->name, Ty::unknown());
        }
      }
      if ($n instanceof Node\Stmt\Catch_ && $n->var instanceof Node\Expr\Variable && is_string($n->var->name)) {
        $t = Ty::none();
        foreach ($n->types as $ty) $t = $t->union(Ty::of(resolved($ty)));
        $this->assign($s, $n->var->name, $t);
      }
      if ($n instanceof Node\Expr\Instanceof_ && $n->expr instanceof Node\Expr\Variable && is_string($n->expr->name)
        && $n->class instanceof Node\Name) {
        $this->assign($s, $n->expr->name, $this->class_name($n->class, $s));
      }
      foreach ($n->getSubNodeNames() as $sub) {
        $stack[] = $n->$sub;
      }
    }
  }
}

// ---------------------------------------------------------------- rewrite

final class Report {
  public array $renamed = [];
  public array $fallback = [];
  public array $unresolved = [];
  public array $labels = [];
  public int $sites = 0;
}

final class RewriteVisitor extends NodeVisitorAbstract {
  /**
   * Edits: [node, 'name', new name] | [node, 'var', new name] | [node, 'comment', [index, transform]]
   *
   * @var list<array{0: Node, 1: string, 2: mixed}>
   */
  public array $edits = [];
  private array $scopes = [];
  private array $classes = [];
  private array $methods = [];
  private array $ctx_stack = [];

  public function __construct(
    private Index $index,
    private Infer $infer,
    private string $file,
    private array $from_names,
    private array $safe,
    private Report $report,
  ) {}

  public function beforeTraverse(array $nodes) {
    // Top-level script statements (bin/*.php, config/services.php).
    $first = $nodes[0] ?? null;
    $ctx = $first?->getAttribute('name_ctx') ?? empty_ctx();
    $this->ctx_stack = [$ctx];
    $this->scopes = [$this->infer->scope_for($nodes, new Scope(null, null), $ctx)];
    return null;
  }

  private function ctx(): NameContext {
    return end($this->ctx_stack);
  }

  private function scope(): Scope {
    return end($this->scopes);
  }

  private function cls(): ?string {
    return $this->classes ? end($this->classes) : null;
  }

  private function line(Node $n): string {
    return $this->file . ':' . $n->getStartLine();
  }

  public function enterNode(Node $node) {
    if ($node->getAttribute('name_ctx') !== null) {
      $this->ctx_stack[] = $node->getAttribute('name_ctx');
    }
    $ctx = $this->ctx();

    if ($node instanceof Node\Stmt\ClassLike) {
      $id = class_id($node, $this->file);
      $this->classes[] = $id;
      if ($id !== null) {
        $this->rename_declarations($node, $id);
      }
      $this->comments($node, $id);
      return null;
    }

    if ($node instanceof Node\FunctionLike) {
      $outer = $this->scope();
      $cls = $this->cls();
      $parent = $cls !== null ? ($this->index->classes[$cls]['parent'] ?? null) : null;
      if ($node instanceof Node\Expr\ArrowFunction) {
        $s = clone $outer;
      } else {
        $s = new Scope($node instanceof Node\Stmt\Function_ ? null : $cls, $node instanceof Node\Stmt\Function_ ? null : $parent);
        if ($node instanceof Node\Expr\Closure) {
          foreach ($node->uses as $u) {
            if (is_string($u->var->name)) $s->vars[$u->var->name] = $outer->vars[$u->var->name] ?? Ty::unknown();
          }
        }
      }
      $stmts = $node instanceof Node\Expr\ArrowFunction ? [$node->expr] : ($node->getStmts() ?? []);
      $doc = $node->getDocComment()?->getText();
      $s = $this->infer->scope_for($stmts, $s, $ctx, $node->getParams(), $doc);
      $hint = $node->getAttribute('param_hint');
      if ($hint instanceof Ty) {
        foreach ($node->getParams() as $p) {
          if ($p->type === null && $p->var instanceof Node\Expr\Variable && is_string($p->var->name)) {
            $s->vars[$p->var->name] = $hint;
            // Re-run the body with the hinted parameters.
            $s = $this->infer->scope_for($stmts, $s, $ctx);
          }
        }
      }
      $this->scopes[] = $s;
      $this->methods[] = $node instanceof Node\Stmt\ClassMethod ? $node : null;
    }

    $this->comments($node, $this->cls());

    $s = $this->scope();

    if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
      && $node->name instanceof Node\Identifier) {
      $name = $node->name->toString();
      if (isset($this->from_names['method'][$name])) {
        $this->site($node->name, 'method', $name, $this->infer->expr($node->var, $s));
      }
      $this->string_member_call($node, $s);
    }
    if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
      $name = $node->name->toString();
      $recv = $node->class instanceof Node\Name ? $this->infer->class_name($node->class, $s) : $this->infer->expr($node->class, $s);
      if (isset($this->from_names['method'][$name])) {
        $this->site($node->name, 'method', $name, $recv);
      }
      if (strtolower($name) === '__construct' && $node->class instanceof Node\Name) {
        $this->named_args($node->args, $recv);
      }
    }
    if (($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
      && $node->name instanceof Node\Identifier) {
      $name = $node->name->toString();
      if (isset($this->from_names['property'][$name])) {
        $this->site($node->name, 'property', $name, $this->infer->expr($node->var, $s));
      }
    }
    if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
      $name = $node->name->toString();
      if (isset($this->from_names['property'][$name])) {
        $recv = $node->class instanceof Node\Name ? $this->infer->class_name($node->class, $s) : $this->infer->expr($node->class, $s);
        $this->site($node->name, 'property', $name, $recv);
      }
    }
    if ($node instanceof Node\Expr\New_) {
      $recv = $node->class instanceof Node\Stmt\Class_ ? Ty::of((string) $node->class->getAttribute('anon_id'))
        : ($node->class instanceof Node\Name ? $this->infer->class_name($node->class, $s) : Ty::unknown());
      $this->named_args($node->args, $recv);
      $this->reflection_new($node, $s);
    }
    if ($node instanceof Node\Attribute) {
      $this->named_args($node->args, Ty::of(resolved($node->name)));
    }
    if ($node instanceof Node\Expr\Array_) {
      $this->array_callable($node, $s);
    }
    if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
      $fn = strtolower($node->name->toString());
      if (in_array($fn, ['method_exists', 'property_exists', 'is_callable'], true) && count($node->args) >= 2
        && $node->args[1] instanceof Node\Arg && $node->args[1]->value instanceof Node\Scalar\String_) {
        $kind = $fn === 'property_exists' ? 'property' : 'method';
        $v = $node->args[1]->value;
        $recv = $this->infer->class_const($node->args[0]->value, $s);
        $t = $recv !== null ? Ty::of($recv) : $this->infer->expr($node->args[0]->value, $s);
        if (isset($this->from_names[$kind][$v->value])) $this->site($v, $kind, $v->value, $t);
      }
    }
    if ($node instanceof Node\Scalar\String_) {
      $this->string_label($node);
    }
    return null;
  }

  public function leaveNode(Node $node) {
    if ($node instanceof Node\Stmt\ClassLike) {
      array_pop($this->classes);
    }
    if ($node instanceof Node\FunctionLike) {
      array_pop($this->scopes);
      array_pop($this->methods);
    }
    if ($node->getAttribute('name_ctx') !== null) {
      array_pop($this->ctx_stack);
    }
    return null;
  }

  // ------------------------------------------------------------ declarations

  private function rename_declarations(Node\Stmt\ClassLike $node, string $id): void {
    foreach ($node->stmts as $stmt) {
      if ($stmt instanceof Node\Stmt\ClassMethod) {
        $name = $stmt->name->toString();
        $to = $this->index->rename_for($id, 'method', $name);
        if ($to !== null) $this->edit($stmt->name, $to, "decl method $id::$name");
        if ($stmt->name->toLowerString() === '__construct') {
          $this->rename_promoted($stmt, $id);
        }
        if ($name === 'getSubscribedEvents') {
          $this->subscribed_events($stmt, $id);
        }
      }
      if ($stmt instanceof Node\Stmt\Property) {
        foreach ($stmt->props as $p) {
          $name = $p->name->toString();
          $to = $this->index->rename_for($id, 'property', $name);
          if ($to !== null) $this->edit($p->name, $to, "decl property $id::$name");
        }
      }
    }
  }

  private function rename_promoted(Node\Stmt\ClassMethod $ctor, string $id): void {
    $renames = [];
    foreach ($ctor->params as $param) {
      if ($param->flags === 0 || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) continue;
      $to = $this->index->rename_for($id, 'property', $param->var->name);
      if ($to === null) continue;
      $renames[$param->var->name] = $to;
    }
    if (!$renames) return;
    // The parameter variable everywhere in the constructor (signature, body, closures' use).
    $finder = new NodeTraverser();
    $edits = &$this->edits;
    $finder->addVisitor(new class($renames, $edits) extends NodeVisitorAbstract {
      public function __construct(private array $renames, private array &$edits) {}

      public function enterNode(Node $n) {
        if ($n instanceof Node\Expr\Variable && is_string($n->name) && isset($this->renames[$n->name])) {
          $this->edits[] = [$n, 'var', $this->renames[$n->name]];
        }
        return null;
      }
    });
    $finder->traverse([$ctor]);
    $this->report->renamed[] = $this->line($ctor) . ' promoted ' . json_encode($renames);
    // @param tags of the constructor docblock.
    $this->doc_params($ctor, $renames);
    foreach ($ctor->params as $param) {
      if ($param->var instanceof Node\Expr\Variable && isset($renames[$param->var->name])) {
        $this->doc_params($param, $renames);
      }
    }
  }

  private function doc_params(Node $n, array $renames): void {
    $transform = fn (string $text): string => realign($text, preg_replace_callback(
      '/(@(?:psalm-|phpstan-)?param\s+[^$\n]*?\$)(\w+)\b/',
      fn ($m) => $m[1] . ($renames[$m[2]] ?? $m[2]),
      $text,
    ));
    foreach ($n->getComments() as $i => $c) {
      if ($transform($c->getText()) !== $c->getText()) $this->edits[] = [$n, 'comment', [$i, $transform]];
    }
  }

  private function subscribed_events(Node\Stmt\ClassMethod $m, string $id): void {
    $finder = new NodeVisitor\FindingVisitor(fn (Node $n) => $n instanceof Node\Scalar\String_);
    $t = new NodeTraverser($finder);
    $t->traverse([$m]);
    foreach ($finder->getFoundNodes() as $s) {
      $to = $this->index->rename_for($id, 'method', $s->value);
      if ($to !== null) $this->edit($s, $to, "subscribed event method $id::{$s->value}");
    }
  }

  // ------------------------------------------------------------ call sites

  /**
   * Decide one site. $ident is an Identifier/VarLikeIdentifier/String_ to rename.
   */
  private function site(Node $ident, string $kind, string $name, Ty $recv, string $what = 'site'): void {
    $this->report->sites++;
    $targets = [];
    $declared_plain = [];
    foreach (array_keys($recv->classes) as $c) {
      $st = $this->index->status($c, $kind, $name);
      if ($st[0] === 'renamed') $targets[$st[1]][] = $c;
      if ($st[0] === 'declared') $declared_plain[] = $c;
    }
    $where = $this->line($ident) . " $kind $name";
    if (count($targets) > 1) {
      $this->report->unresolved[] = "$where: conflicting targets " . json_encode($targets);
      return;
    }
    if ($targets && $declared_plain) {
      $this->report->unresolved[] = "$where: receiver mixes renamed " . json_encode($targets)
        . ' and unrenamed ' . json_encode($declared_plain);
      return;
    }
    if ($targets) {
      $to = array_key_first($targets);
      $this->edit($ident, $to, "$what $kind {$targets[$to][0]}::$name");
      return;
    }
    if ($declared_plain) return; // resolved to classes that keep the name
    // Unresolved receiver.
    $safe = $this->safe[$kind][$name] ?? null;
    if ($safe !== null) {
      $this->edit($ident, $safe, null);
      $this->report->fallback[] = "$where -> $safe (receiver " . ($recv->classes ? implode('|', array_keys($recv->classes)) : 'unknown') . ')';
      return;
    }
    $this->report->unresolved[] = "$where: receiver " . ($recv->classes ? implode('|', array_keys($recv->classes)) : 'unknown')
      . ' not resolved and the name is not globally unambiguous';
  }

  private function named_args(array $args, Ty $recv): void {
    $named = [];
    foreach ($args as $a) {
      if ($a instanceof Node\Arg && $a->name instanceof Node\Identifier) $named[] = $a;
    }
    if (!$named) return;
    foreach (array_keys($recv->classes) as $c) {
      [$decl, $promoted] = $this->index->ctor_promoted($c);
      if ($decl === null) continue;
      foreach ($named as $a) {
        $n = $a->name->toString();
        if (!isset($promoted[$n])) continue;
        $to = $this->index->rename_for($decl, 'property', $n);
        if ($to !== null) $this->edit($a->name, $to, "named arg $decl::$n");
      }
      return;
    }
    foreach ($named as $a) {
      $n = $a->name->toString();
      if (isset($this->from_names['property'][$n]) && !$recv->classes) {
        $this->report->unresolved[] = $this->line($a) . " named arg $n: constructor class not resolved";
      }
    }
  }

  private function array_callable(Node\Expr\Array_ $node, Scope $s): void {
    if (count($node->items) !== 2) return;
    [$a, $b] = $node->items;
    if ($a === null || $b === null || $a->key !== null || $b->key !== null) return;
    if (!$b->value instanceof Node\Scalar\String_) return;
    $name = $b->value->value;
    if (!isset($this->from_names['method'][$name])) return;
    $c = $this->infer->class_const($a->value, $s);
    if ($c !== null) {
      $t = Ty::of($c);
    } elseif ($a->value instanceof Node\Expr\FuncCall && $a->value->name instanceof Node\Name
      && in_array(strtolower($a->value->name->toString()), ['service', 'inline_service', 'ref'], true)
      && isset($a->value->args[0]) && $a->value->args[0] instanceof Node\Arg
      && ($sc = $this->infer->class_const($a->value->args[0]->value, $s)) !== null) {
      $t = Ty::of($sc);
    } else {
      $t = $this->infer->expr($a->value, $s);
    }
    $this->site($b->value, 'method', $name, $t, 'callable');
  }

  /** `new ReflectionMethod(X::class, 'foo')`, `new ReflectionProperty($x, 'foo')`. */
  private function reflection_new(Node\Expr\New_ $node, Scope $s): void {
    if (!$node->class instanceof Node\Name || count($node->args) < 2) return;
    $cls = strtolower(resolved($node->class));
    $kind = match ($cls) {
      'reflectionmethod' => 'method',
      'reflectionproperty' => 'property',
      default => null,
    };
    if ($kind === null || !$node->args[1] instanceof Node\Arg || !$node->args[1]->value instanceof Node\Scalar\String_) return;
    $v = $node->args[1]->value;
    if (!isset($this->from_names[$kind][$v->value])) return;
    $c = $this->infer->class_const($node->args[0]->value, $s);
    $this->site($v, $kind, $v->value, $c !== null ? Ty::of($c) : $this->infer->expr($node->args[0]->value, $s), 'reflection');
  }

  /** `(new ReflectionClass(X::class))->getMethod('foo')` and friends. */
  private function string_member_call(Node\Expr $node, Scope $s): void {
    $n = strtolower($node->name->toString());
    // PHPUnit doubles: $mock->method('foo'), $mock->expects(...)->method('foo').
    if ($n === 'method' && isset($node->args[0]) && $node->args[0] instanceof Node\Arg
      && $node->args[0]->value instanceof Node\Scalar\String_
      && isset($this->from_names['method'][$node->args[0]->value->value])) {
      $base = $node->var;
      if ($base instanceof Node\Expr\MethodCall && $base->name instanceof Node\Identifier
        && $base->name->toLowerString() === 'expects') {
        $base = $base->var;
      }
      $v = $node->args[0]->value;
      $this->site($v, 'method', $v->value, $this->infer->expr($base, $s), 'mock');
      return;
    }
    $kind = match ($n) {
      'getmethod', 'hasmethod' => 'method',
      'getproperty', 'hasproperty' => 'property',
      default => null,
    };
    if ($kind === null || !isset($node->args[0]) || !$node->args[0] instanceof Node\Arg
      || !$node->args[0]->value instanceof Node\Scalar\String_) return;
    $v = $node->args[0]->value;
    if (!isset($this->from_names[$kind][$v->value])) return;
    $recv = $node->var;
    if ($recv instanceof Node\Expr\New_ && $recv->class instanceof Node\Name
      && in_array(strtolower(resolved($recv->class)), ['reflectionclass', 'reflectionobject'], true) && isset($recv->args[0])) {
      $c = $this->infer->class_const($recv->args[0]->value, $s);
      $this->site($v, $kind, $v->value, $c !== null ? Ty::of($c) : $this->infer->expr($recv->args[0]->value, $s), 'reflection');
      return;
    }
    $this->report->unresolved[] = $this->line($node) . " $kind {$v->value}: string member name on " . $node->name->toString() . '()';
  }

  /** A string equal to the enclosing method's old name is its label: `$this->write('retryLater', ...)`. */
  private function string_label(Node\Scalar\String_ $node): void {
    $m = $this->methods ? end($this->methods) : null;
    $cls = $this->cls();
    if (!$m instanceof Node\Stmt\ClassMethod || $cls === null) return;
    if ($node->value !== $m->name->toString()) return;
    $to = $this->index->rename_for($cls, 'method', $node->value);
    if ($to === null) return;
    $this->edit($node, $to, null);
    $this->report->labels[] = $this->line($node) . " '{$node->value}' -> '$to'";
  }

  // ------------------------------------------------------------ comments

  private function comments(Node $node, ?string $cls): void {
    $comments = $node->getComments();
    if (!$comments) return;
    $ctx = $this->ctx();
    $index = $this->index;
    $safe = $this->safe;
    $from = $this->from_names;
    $transform = fn (string $text): string => rewrite_comment($text, $cls, $ctx, $index, $safe, $from);
    foreach ($comments as $i => $c) {
      if ($transform($c->getText()) !== $c->getText()) $this->edits[] = [$node, 'comment', [$i, $transform]];
    }
  }

  private function edit(Node $n, string $to, ?string $why): void {
    $this->edits[] = [$n, 'name', $to];
    if ($why !== null) $this->report->renamed[] = $this->line($n) . " $why -> $to";
  }
}

/**
 * Comment rewrite: qualified `X::foo` / `X::$foo` when X resolves; unqualified
 * `foo(`, `->foo`, `::foo`, `` `foo` `` when the enclosing class renames foo or
 * the name is globally unambiguous.
 */
function rewrite_comment(string $text, ?string $cls, NameContext $ctx, Index $index, array $safe, array $from_names): string {
  $any = array_merge(array_keys($from_names['method']), array_keys($from_names['property']));
  $hit = false;
  foreach ($any as $n) {
    if (str_contains($text, $n)) {
      $hit = true;
      break;
    }
  }
  if (!$hit) return $text;
  $orig = $text;

  $local = function (string $name, ?string $kind) use ($cls, $index, $safe): ?string {
    $kinds = $kind ? [$kind] : ['method', 'property'];
    if ($cls !== null) {
      foreach ($kinds as $k) {
        $to = $index->rename_for($cls, $k, $name);
        if ($to !== null) return $to;
      }
    }
    $targets = [];
    foreach ($kinds as $k) {
      if (isset($safe[$k][$name])) $targets[$safe[$k][$name]] = true;
    }
    return count($targets) === 1 ? array_key_first($targets) : null;
  };

  // 1. Qualified: Class::member / Class::$member / Class::member()
  $text = preg_replace_callback('/(?<![\w\\\\$])(\\\\?[A-Z][\w]*(?:\\\\[A-Z]\w*)*)::(\$?)([A-Za-z_]\w*)/', function ($m) use ($ctx, $index, $local) {
    $class = $m[1];
    $low = strtolower($class);
    if ($low === 'self' || $low === 'static' || $low === 'parent') {
      $to = $local($m[3], $m[2] === '$' ? 'property' : null);
      return $to !== null ? "$class::{$m[2]}$to" : $m[0];
    }
    $fq = lc(resolve_doc_name($class, $ctx));
    if (!$index->known($fq)) {
      // A short name the file does not import: find a unique indexed class with that base name.
      $cands = [];
      foreach ($index->classes as $id => $_) {
        if (str_ends_with($id, '\\' . strtolower(ltrim($class, '\\')))) $cands[] = $id;
      }
      if (count($cands) !== 1) {
        // Ambiguous or unknown class: fall back to the global rule.
        $to = $local($m[3], $m[2] === '$' ? 'property' : null);
        return $to !== null && $index->known($fq) ? "$class::{$m[2]}$to" : (count($cands) === 0 && $to !== null ? "$class::{$m[2]}$to" : $m[0]);
      }
      $fq = $cands[0];
    }
    $kind = $m[2] === '$' ? 'property' : null;
    foreach ($kind ? [$kind] : ['method', 'property'] as $k) {
      $to = $index->rename_for($fq, $k, $m[3]);
      if ($to !== null) return "$class::{$m[2]}$to";
    }
    return $m[0];
  }, $text);

  // 2. Unqualified member mentions.
  $text = preg_replace_callback('/(?<=->|\?->)([A-Za-z_]\w*)|(?<![\w$\\\\:>])([A-Za-z_]\w*)(?=\()|(?<=`)([A-Za-z_]\w*)(?=(?:\(\))?`)/', function ($m) use ($local, $from_names) {
    $name = ($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
    if ($name === '' || (!isset($from_names['method'][$name]) && !isset($from_names['property'][$name]))) return $m[0];
    $kind = ($m[2] ?? '') !== '' ? 'method' : null;
    $to = $local($name, $kind);
    return $to ?? $m[0];
  }, $text);

  // 3. Bare camelCase mentions in prose ("success → markDelivered"). Not a
  // `$variable`, not a quoted string or array-shape key, not part of a path.
  $text = preg_replace_callback('/(?<![\w$\\\\\'"\/.:>-])([a-z]+[A-Z]\w*)\b(?![\'"(]|\??\s*:(?!:)|\\\\)/', function ($m) use ($local, $from_names) {
    $name = $m[1];
    if (!isset($from_names['method'][$name]) && !isset($from_names['property'][$name])) return $m[0];
    return $local($name, null) ?? $m[0];
  }, $text);
  return realign($orig, $text);
}

/**
 * Keep column alignment: on every changed line, a segment that followed a
 * run of 2+ spaces starts at its old column again (the run shrinks or grows,
 * never below one space). `@param Type $eventId      text` stays aligned.
 */
function realign(string $before, string $after): string {
  $b = explode("\n", $before);
  $a = explode("\n", $after);
  if (count($a) !== count($b)) return $after;
  foreach ($a as $i => $line) {
    if ($line === $b[$i] || !preg_match('/\S {2,}\S/', $b[$i])) continue;
    $old = preg_split('/( {2,})/', $b[$i], -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $new = preg_split('/( {2,})/', $line, -1, PREG_SPLIT_NO_EMPTY);
    if (count($old) !== count($new)) continue;
    $out = $old[0][1] === 0 ? $new[0] : str_repeat(' ', $old[0][1]) . ltrim($new[0], ' ');
    for ($k = 1, $n = count($new); $k < $n; $k++) {
      $out .= str_repeat(' ', max(1, $old[$k][1] - strlen($out))) . $new[$k];
    }
    $a[$i] = $out;
  }
  return implode("\n", $a);
}

// ---------------------------------------------------------------- main

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$index = new Index($table);

$t0 = microtime(true);
$index_files = [];
foreach ($index_dirs as $d) $index_files = array_merge($index_files, php_files($d));
$index_files = array_values(array_unique($index_files));
foreach ($index_files as $f) {
  try {
    $ast = $parser->parse((string) file_get_contents($f));
  } catch (\PhpParser\Error $e) {
    fwrite(STDERR, "[rename] parse error in $f: {$e->getMessage()}\n");
    continue;
  }
  $ast = prepare($ast, $f);
  (new NodeTraverser(new IndexVisitor($index, $f)))->traverse($ast);
}

// Vendor and PHP declared names, for the globally-unambiguous rule.
foreach (get_declared_classes() as $c) {
  $r = new ReflectionClass($c);
  if (!$r->isInternal()) continue;
  foreach ($r->getMethods() as $m) $index->vendor_names['method'][$m->getName()] = true;
  foreach ($r->getProperties() as $p) $index->vendor_names['property'][$p->getName()] = true;
}
foreach (array_unique($vendor_dirs) as $vd) {
  if (!is_dir($vd)) continue;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vd, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || str_contains($p, '/vendor/tangible/')) continue;
    $code = (string) file_get_contents($p);
    if (preg_match_all('/function\s+&?\s*([A-Za-z_]\w*)\s*\(/', $code, $m)) {
      foreach ($m[1] as $n) $index->vendor_names['method'][$n] = true;
    }
    if (preg_match_all('/(?:public|protected|private|var)\s+(?:(?:readonly|static)\s+)*(?:[\w\\\\?|&]+\s+)?\$([A-Za-z_]\w*)/', $code, $m)) {
      foreach ($m[1] as $n) $index->vendor_names['property'][$n] = true;
    }
    if (preg_match_all('/@(?:method|property(?:-read|-write)?)\s+[^\n]*?(?:\$|\s)([A-Za-z_]\w*)\s*[\(\n]/', $code, $m)) {
      foreach ($m[1] as $n) {
        $index->vendor_names['method'][$n] = true;
        $index->vendor_names['property'][$n] = true;
      }
    }
  }
}

// Globally unambiguous names: the table renames the name to one target
// everywhere, no indexed class keeps a declaration of it, and no vendor/PHP
// class declares it. (Targets come from the table, so a re-run on an already
// renamed tree reaches the same verdicts.)
$safe = ['method' => [], 'property' => []];
$unsafe_why = [];
foreach (['method', 'property'] as $kind) {
  foreach (array_keys($from_names[$kind]) as $name) {
    $targets = [];
    foreach ($table as $e) {
      if ($e['kind'] === $kind && $e['from'] === $name) $targets[$e['to']] = true;
    }
    $plain = [];
    foreach ($index->classes as $id => $c) {
      $declares = $kind === 'method' ? isset($c['methods'][strtolower($name)]) : isset($c['props'][$name]);
      if ($declares && $index->rename_for($id, $kind, $name) === null) $plain[] = $id;
    }
    if (isset($index->vendor_names[$kind][$name])) {
      $unsafe_why["$kind $name"] = 'declared in vendor/PHP';
      continue;
    }
    if ($plain) {
      $unsafe_why["$kind $name"] = 'kept by ' . implode(', ', array_slice($plain, 0, 3));
      continue;
    }
    if (count($targets) !== 1) {
      $unsafe_why["$kind $name"] = 'targets ' . implode(', ', array_keys($targets));
      continue;
    }
    $safe[$kind][$name] = array_key_first($targets);
  }
}

// Lineage consistency: a class whose lineage renames a member must not keep
// an unrenamed declaration of it anywhere in that lineage.
$lineage_errors = [];
foreach ($index->classes as $id => $c) {
  foreach (['method' => $c['methods'], 'property' => $c['props']] as $kind => $members) {
    foreach ($members as $key => $info) {
      $name = $kind === 'method' ? $info['name'] : $key;
      $mine = $index->rename_for($id, $kind, $name);
      foreach ($index->lineage($id) as $a) {
        if ($a === $id) continue;
        if (!isset($index->classes[$a])) {
          if ($mine !== null && $index->declares($a, $kind, $name) === true) {
            $lineage_errors[] = "$id::$name renamed to $mine but external ancestor $a declares $name";
          }
          continue;
        }
        $theirs_declared = $kind === 'method' ? isset($index->classes[$a]['methods'][strtolower($name)]) : isset($index->classes[$a]['props'][$name]);
        if (!$theirs_declared) continue;
        $theirs = $index->rename_for($a, $kind, $name);
        if ($theirs !== $mine) {
          $lineage_errors[] = "$id::$name -> " . ($mine ?? '(kept)') . " but ancestor $a::$name -> " . ($theirs ?? '(kept)');
        }
      }
    }
  }
}

$report = new Report();
$infer = new Infer($index);
$printer = new PrettyPrinter\Standard();
$changed = [];
$write_files = [];
foreach ($write_dirs as $d) $write_files = array_merge($write_files, php_files($d));
$write_files = array_values(array_unique($write_files));
$rel = fn (string $p) => str_starts_with($p, $target . '/') ? substr($p, strlen($target) + 1) : $p;

foreach ($write_files as $f) {
  $code = (string) file_get_contents($f);
  try {
    $old = $parser->parse($code);
  } catch (\PhpParser\Error $e) {
    fwrite(STDERR, "[rename] parse error in $f: {$e->getMessage()}\n");
    continue;
  }
  $tokens = $parser->getTokens();
  $new = (new NodeTraverser(new NodeVisitor\CloningVisitor()))->traverse($old);
  $new = prepare($new, $f);
  $rw = new RewriteVisitor($index, $infer, $rel($f), $from_names, $safe, $report);
  (new NodeTraverser($rw))->traverse($new);
  if (!$rw->edits) continue;

  // Apply the edits after the whole file was analysed, so inference never
  // sees a half-renamed tree.
  $string_swaps = [];
  $comment_edits = [];
  foreach ($rw->edits as [$n, $what, $value]) {
    if ($what === 'name') {
      if ($n instanceof Node\Scalar\String_) {
        $string_swaps[spl_object_id($n)] = [$n, $value];
      } else {
        $n->name = $value;
      }
    } elseif ($what === 'var') {
      $n->name = $value;
    } elseif ($what === 'comment') {
      $comment_edits[spl_object_id($n)][0] = $n;
      $comment_edits[spl_object_id($n)][1][$value[0]][] = $value[1];
    }
  }
  // Comments: each node's edited comments are rebuilt (several nodes may share one comment).
  foreach ($comment_edits as [$n, $by_index]) {
    $cs = $n->getComments();
    foreach ($by_index as $i => $transforms) {
      $c = $cs[$i];
      $text = $c->getText();
      foreach ($transforms as $tr) $text = $tr($text);
      if ($text === $c->getText()) continue;
      $cls = $c instanceof Comment\Doc ? Comment\Doc::class : Comment::class;
      $cs[$i] = new $cls($text, $c->getStartLine(), $c->getStartFilePos(), $c->getStartTokenPos(),
        $c->getEndLine(), $c->getEndFilePos(), $c->getEndTokenPos());
    }
    $n->setAttribute('comments', $cs);
  }
  // Strings: replace the node in its parent.
  if ($string_swaps) {
    $swapper = new class($string_swaps) extends NodeVisitorAbstract {
      public function __construct(private array $swaps) {}

      public function leaveNode(Node $n) {
        if (!isset($this->swaps[spl_object_id($n)])) return null;
        [$s, $to] = $this->swaps[spl_object_id($n)];
        $attrs = $s->getAttributes();
        unset($attrs['rawValue']);
        return new Node\Scalar\String_($to, $attrs);
      }
    };
    $new = (new NodeTraverser($swapper))->traverse($new);
  }
  $out = $printer->printFormatPreserving($new, $old, $tokens);
  if ($out !== $code) {
    $changed[] = $rel($f);
    if (!$dry_run) file_put_contents($f, $out);
  }
}

// Markdown docs: code-ish mentions of globally unambiguous names.
$changed_docs = [];
foreach ($doc_files as $df) {
  if (!is_file($df)) continue;
  $md = (string) file_get_contents($df);
  $out = rewrite_comment($md, null, empty_ctx(), $index, $safe, $from_names);
  if ($out !== $md) {
    $changed_docs[] = $rel($df);
    if (!$dry_run) file_put_contents($df, $out);
  }
}

// Leftovers: identifiers and strings that still carry a table name.
$leftovers = [];
if (!$dry_run) {
  foreach ($write_files as $f) {
    try {
      $ast = $parser->parse((string) file_get_contents($f));
    } catch (\PhpParser\Error) {
      continue;
    }
    $finder = new NodeVisitor\FindingVisitor(function (Node $n) use ($from_names) {
      if ($n instanceof Node\Identifier || $n instanceof Node\VarLikeIdentifier) {
        return isset($from_names['method'][$n->name]) || isset($from_names['property'][$n->name]);
      }
      if ($n instanceof Node\Scalar\String_) {
        return isset($from_names['method'][$n->value]) || isset($from_names['property'][$n->value]);
      }
      return false;
    });
    (new NodeTraverser($finder))->traverse($ast);
    foreach ($finder->getFoundNodes() as $n) {
      $v = $n instanceof Node\Scalar\String_ ? "'{$n->value}'" : $n->name;
      $leftovers[] = $rel($f) . ':' . $n->getStartLine() . " $v";
    }
  }
}

$summary = [
  'target' => $target,
  'dry_run' => $dry_run,
  'index_classes' => count($index->classes),
  'table_entries' => count($table),
  'files_changed' => count($changed),
  'docs_changed' => $changed_docs,
  'renamed_sites' => count($report->renamed),
  'fallback_sites' => count($report->fallback),
  'unresolved_sites' => count($report->unresolved),
  'lineage_errors' => $lineage_errors,
  'unresolved' => $report->unresolved,
  'fallback' => $report->fallback,
  'labels' => $report->labels,
  'not_globally_unambiguous' => $unsafe_why,
  'globally_unambiguous' => $safe,
  'leftovers' => $leftovers,
  'changed' => $changed,
  'renamed' => $report->renamed,
  'seconds' => round(microtime(true) - $t0, 1),
];
if (isset($opts['report'])) {
  file_put_contents($opts['report'], json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
fwrite(STDOUT, sprintf(
  "[rename] %d classes indexed, %d files %s, %d sites renamed by type, %d by unambiguous name, %d unresolved, %d lineage errors, %d leftovers (%.1fs)\n",
  $summary['index_classes'], count($changed), $dry_run ? 'would change' : 'changed', $summary['renamed_sites'],
  $summary['fallback_sites'], $summary['unresolved_sites'], count($lineage_errors), count($leftovers), $summary['seconds'],
));
foreach ($lineage_errors as $e) fwrite(STDOUT, "  lineage: $e\n");
foreach ($report->unresolved as $u) fwrite(STDOUT, "  unresolved: $u\n");
exit($lineage_errors ? 2 : 0);
