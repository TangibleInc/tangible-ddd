<?php
/**
 * Naming inventory for the house-style rename (snake_case, bare-noun accessors).
 *
 * Lists every public/protected method and property (promoted constructor
 * properties included) declared in the library packages that is not in house
 * style and not out of scope (0.6.6 frozen API, framework-required names,
 * PHPUnit test methods). One entry per root declaration: a member that an
 * implementation inherits from a library interface, abstract class or trait
 * is recorded on that root, with the re-declaring classes as implementations.
 *
 * Usage: php tools/naming/inventory.php [--txp=/path/to/txp] [--tag=v0.6.6] [--out=path]
 *
 * Without --out the JSON goes to stdout and the summary to stderr, so a run
 * never touches the tree. --out=path writes the JSON to that file (parent
 * directories created) and prints the summary to stdout. The tracked
 * docs/extraction/naming/inventory.json is a historical snapshot (761aef5,
 * before the rename); do not point --out at it.
 *
 * Call-site counts are name-matched (not type-resolved): every `->name(`,
 * `?->name(`, `::name(` for methods, `->name` / `::$name` for properties,
 * named arguments for promoted properties, and exact string literals (for
 * callables and DI `->call('name')`). Two entries that share a name share
 * their counts; `shared_name_with` says so.
 */

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$opts = getopt('', ['txp::', 'out::', 'tag::']);
$txp = rtrim($opts['txp'] ?? '/Users/titustc/tgbl/txp-slices', '/');
$out = isset($opts['out']) && is_string($opts['out']) && $opts['out'] !== '' ? $opts['out'] : null;
$tag = $opts['tag'] ?? 'v0.6.6';

// Reflection on external (vendor) ancestors: load every package vendor tree
// so Symfony / Doctrine / PHPUnit / PSR parents resolve. Only non-TangibleDDD
// names are ever reflected, so duplicate library copies never load.
foreach (['packages/ddd-symfony/vendor/autoload.php', 'packages/ddd-conformance/vendor/autoload.php'] as $al) {
  if (is_file("$root/$al")) {
    require_once "$root/$al";
  }
}
if (is_file("$txp/vendor/autoload.php")) {
  // TXP vendor only for external lookups as well; registered last.
  require_once "$txp/vendor/autoload.php";
}

const AREAS = [
  'core-outbox-delivery',
  'core-process-lock-wakeup',
  'core-effects-audit-ops-codec-misc',
  'pdo',
  'wp-adapters',
  'symfony',
  'conformance',
];

// Names a framework calls by convention or override. Applied only when the
// declaring class has at least one external (non-library) ancestor, or
// unconditionally for the PHP magic/SPL ones.
const FRAMEWORK_ALWAYS = [
  'jsonSerialize', 'getIterator', 'count', 'offsetGet', 'offsetSet',
  'offsetExists', 'offsetUnset', 'serialize', 'unserialize',
];
const FRAMEWORK_WITH_EXTERNAL_ANCESTOR = [
  'process', 'load', 'getConfigTreeBuilder', 'getSubscribedEvents',
  'configure', 'execute', 'interact', 'initialize', 'getAlias', 'prepend',
  'build', 'boot', 'shutdown', 'getPath', 'getContainerExtension',
  'registerBundles', 'configureContainer', 'configureRoutes', 'getProjectDir',
  'getCacheDir', 'getLogDir', 'setUp', 'tearDown', 'setUpBeforeClass',
  'tearDownAfterClass', 'assertPreConditions', 'assertPostConditions',
  'onNotSuccessfulTest', 'send', 'get', 'ack', 'reject', 'handle',
  'supports', 'encode', 'decode', 'getName', 'getDescription', 'getUp',
  'up', 'down', 'getSQLDeclaration', 'convertToPHPValue',
  'convertToDatabaseValue', 'requiresSQLCommentHint',
];

function rel(string $root, string $path): string {
  return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}

function php_files(string $dir): array {
  if (!is_dir($dir)) {
    return [];
  }
  $out = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    $p = $f->getPathname();
    if (str_ends_with($p, '.php') && !str_contains($p, '/vendor/') && !str_contains($p, '/var/')) {
      $out[] = $p;
    }
  }
  sort($out);
  return $out;
}

function area_for(string $rel): ?string {
  if (str_starts_with($rel, 'packages/ddd-conformance/')) return 'conformance';
  if (str_starts_with($rel, 'packages/ddd-symfony/')) return 'symfony';
  if (str_starts_with($rel, 'packages/ddd-wp/')) return 'wp-adapters';
  if (str_starts_with($rel, 'packages/ddd-core/src/Defaults/Pdo/')) return 'pdo';
  if (!str_starts_with($rel, 'packages/ddd-core/src/')) return null;
  $p = substr($rel, strlen('packages/ddd-core/src/'));
  $outbox_dirs = ['Runtime/Outbox/', 'Runtime/Delivery/', 'Application/Outbox/', 'Application/Events/',
    'Application/EventHandlers/', 'Infra/Consumers/'];
  $process_dirs = ['Runtime/Process/', 'Runtime/Lock/', 'Runtime/Scheduling/', 'Application/Process/',
    'Application/BehaviourWorkflows/'];
  foreach ($outbox_dirs as $d) if (str_starts_with($p, $d)) return 'core-outbox-delivery';
  foreach ($process_dirs as $d) if (str_starts_with($p, $d)) return 'core-process-lock-wakeup';
  $outbox_files = ['Runtime/Drain.php', 'Runtime/DrainReport.php', 'Runtime/IFactObserver.php',
    'Runtime/NullFactObserver.php', 'Runtime/OrderedListenerDispatcher.php', 'Runtime/ConsumerPrefix.php',
    'Testing/InMemoryOutboxStore.php', 'Testing/InMemoryDeliveryLedger.php', 'Testing/InMemoryTransport.php',
    'Testing/InMemoryRelayPauseStore.php', 'Testing/RecordingRelayWakeup.php', 'Testing/RecordingFactObserver.php',
    'Testing/StaticSubscriberProbe.php', 'Testing/StaticConsumerIdentity.php', 'Testing/IntegrationConformance.php'];
  $process_files = ['Testing/InMemoryProcessStore.php', 'Testing/InMemoryProcessLock.php',
    'Testing/InMemoryNamedLock.php', 'Testing/InMemoryWakeupScheduler.php'];
  if (in_array($p, $outbox_files, true)) return 'core-outbox-delivery';
  if (in_array($p, $process_files, true)) return 'core-process-lock-wakeup';
  return 'core-effects-audit-ops-codec-misc';
}

/** House style: lower snake_case, no get_ accessor prefix (get_by_* finders allowed). */
function style_violation(string $name): ?string {
  if (preg_match('/[A-Z]/', $name)) return 'camelCase';
  if (!preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/', $name)) return 'other';
  if (str_starts_with($name, 'get_') && !str_starts_with($name, 'get_by_')) return 'get_prefix';
  return null;
}

/**
 * Collects class-like declarations and name-matched usages from one file.
 */
final class Collector extends NodeVisitorAbstract {
  public array $classes = [];
  public array $usage = ['method' => [], 'property' => [], 'named_arg' => [], 'string' => []];
  private array $stack = [];

  public function __construct(private string $file) {}

  public function enterNode(Node $node) {
    if ($node instanceof Node\Stmt\ClassLike && isset($node->namespacedName)) {
      $fqcn = $node->namespacedName->toString();
      $kind = match (true) {
        $node instanceof Node\Stmt\Interface_ => 'interface',
        $node instanceof Node\Stmt\Trait_ => 'trait',
        $node instanceof Node\Stmt\Enum_ => 'enum',
        default => ($node->isAbstract() ? 'abstract_class' : 'class'),
      };
      $parents = [];
      $ifaces = [];
      if ($node instanceof Node\Stmt\Class_) {
        if ($node->extends) $parents[] = $node->extends->toString();
        foreach ($node->implements as $i) $ifaces[] = $i->toString();
      } elseif ($node instanceof Node\Stmt\Interface_) {
        foreach ($node->extends as $i) $ifaces[] = $i->toString();
      } elseif ($node instanceof Node\Stmt\Enum_) {
        foreach ($node->implements as $i) $ifaces[] = $i->toString();
      }
      $traits = [];
      $providers = [];
      $members = [];
      foreach ($node->stmts as $stmt) {
        if ($stmt instanceof Node\Stmt\TraitUse) {
          foreach ($stmt->traits as $t) $traits[] = $t->toString();
        }
        if ($stmt instanceof Node\Stmt\ClassMethod) {
          $name = $stmt->name->toString();
          $attrs = self::attr_names($stmt->attrGroups);
          foreach ($stmt->attrGroups as $g) {
            foreach ($g->attrs as $a) {
              if (str_ends_with($a->name->toString(), 'DataProvider') && isset($a->args[0])
                && $a->args[0]->value instanceof Node\Scalar\String_) {
                $providers[] = $a->args[0]->value->value;
              }
            }
          }
          $members['method:' . $name] = [
            'name' => $name,
            'kind' => 'method',
            'visibility' => $kind === 'interface' ? 'public' : self::visibility($stmt->flags),
            'static' => $stmt->isStatic(),
            'abstract' => $kind === 'interface' || $stmt->isAbstract(),
            'line' => $stmt->getStartLine(),
            'attrs' => $attrs,
            'promoted' => false,
          ];
          if ($name === '__construct') {
            foreach ($stmt->params as $param) {
              if ($param->flags === 0 || !($param->var instanceof Node\Expr\Variable) || !is_string($param->var->name)) {
                continue;
              }
              $pn = $param->var->name;
              $members['property:' . $pn] = [
                'name' => $pn,
                'kind' => 'property',
                'visibility' => self::visibility($param->flags),
                'static' => false,
                'abstract' => false,
                'line' => $param->getStartLine(),
                'attrs' => [],
                'promoted' => true,
              ];
            }
          }
        }
        if ($stmt instanceof Node\Stmt\Property) {
          foreach ($stmt->props as $prop) {
            $pn = $prop->name->toString();
            $members['property:' . $pn] = [
              'name' => $pn,
              'kind' => 'property',
              'visibility' => self::visibility($stmt->flags),
              'static' => $stmt->isStatic(),
              'abstract' => false,
              'line' => $prop->getStartLine(),
              'attrs' => [],
              'promoted' => false,
            ];
          }
        }
      }
      $this->classes[$fqcn] = [
        'fqcn' => $fqcn,
        'kind' => $kind,
        'file' => $this->file,
        'parents' => $parents,
        'interfaces' => $ifaces,
        'traits' => $traits,
        'providers' => $providers,
        'members' => $members,
      ];
    }

    if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall
      || $node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier) {
      $this->bump('method', $node->name->toString());
    }
    if (($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
      && $node->name instanceof Node\Identifier) {
      $this->bump('property', $node->name->toString());
    }
    if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
      $this->bump('property', $node->name->toString());
    }
    if ($node instanceof Node\Arg && $node->name instanceof Node\Identifier) {
      $this->bump('named_arg', $node->name->toString());
    }
    if ($node instanceof Node\Scalar\String_ && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $node->value)) {
      $this->bump('string', $node->value);
    }
    return null;
  }

  private function bump(string $bucket, string $name): void {
    $this->usage[$bucket][$name] = ($this->usage[$bucket][$name] ?? 0) + 1;
  }

  private static function visibility(int $flags): string {
    if ($flags & Node\Stmt\Class_::MODIFIER_PRIVATE) return 'private';
    if ($flags & Node\Stmt\Class_::MODIFIER_PROTECTED) return 'protected';
    return 'public';
  }

  private static function attr_names(array $groups): array {
    $out = [];
    foreach ($groups as $g) foreach ($g->attrs as $a) $out[] = $a->name->toString();
    return $out;
  }
}

function parse_code(string $code, string $label): ?array {
  static $parser = null;
  $parser ??= (new ParserFactory())->createForNewestSupportedVersion();
  try {
    $ast = $parser->parse($code);
  } catch (\PhpParser\Error $e) {
    fwrite(STDERR, "[naming] parse error in $label: {$e->getMessage()}\n");
    return null;
  }
  $c = new Collector($label);
  $t = new NodeTraverser();
  $t->addVisitor(new NameResolver());
  $t->addVisitor($c);
  $t->traverse($ast);
  return [$c->classes, $c->usage];
}

function parse_corpus(array $files, string $root): array {
  $classes = [];
  $usage = ['method' => [], 'property' => [], 'named_arg' => [], 'string' => []];
  foreach ($files as $f) {
    $r = parse_code(file_get_contents($f), rel($root, $f));
    if ($r === null) continue;
    $classes += $r[0];
    foreach ($r[1] as $bucket => $names) {
      foreach ($names as $n => $k) $usage[$bucket][$n] = ($usage[$bucket][$n] ?? 0) + $k;
    }
  }
  return [$classes, $usage];
}

// ---------------------------------------------------------------- sources

$decl_dirs = [
  'packages/ddd-core/src',
  'packages/ddd-wp/src',
  'packages/ddd-wp/wordpress',
  'packages/ddd-symfony/src',
  'packages/ddd-symfony/config',
  'packages/ddd-conformance/src',
];
$corpora = [
  'library' => ['packages/ddd-core/src', 'packages/ddd-wp/src', 'packages/ddd-wp/wordpress',
    'packages/ddd-symfony/src', 'packages/ddd-symfony/config', 'ddd-wordpress', 'compat', 'loader'],
  'library_tests' => ['packages/ddd-core/tests', 'packages/ddd-symfony/tests', 'tests'],
  'conformance' => ['packages/ddd-conformance/src', 'packages/ddd-conformance/tests'],
  'txp' => ["$txp/src", "$txp/tests"],
];

$parsed = [];
foreach ($corpora as $name => $dirs) {
  $files = [];
  foreach ($dirs as $d) {
    $abs = str_starts_with($d, '/') ? $d : "$root/$d";
    $files = array_merge($files, php_files($abs));
  }
  $parsed[$name] = parse_corpus($files, $root);
}

// Library declarations (the inventory subject).
$lib = [];
foreach ($decl_dirs as $d) {
  [$cls] = parse_corpus(php_files("$root/$d"), $root);
  $lib += $cls;
}

// Every known class-like across all corpora, for hierarchy walks.
$all = $lib;
foreach ($parsed as $name => [$cls]) {
  foreach ($cls as $fqcn => $c) {
    $c['corpus'] = $name;
    $all[$fqcn] ??= $c;
  }
}
foreach ($lib as $fqcn => $c) {
  $all[$fqcn]['corpus'] = str_starts_with($c['file'], 'packages/ddd-conformance/') ? 'conformance' : 'library';
}

// ---------------------------------------------------------------- 0.6.6 frozen set

$frozen = [];          // "FQCN::name" => true
$frozen_names = [];    // name => true (informational)
$listing = shell_exec('git -C ' . escapeshellarg($root) . ' ls-tree -r --name-only ' . escapeshellarg($tag)
  . ' -- ddd-src ddd-wordpress');
foreach (array_filter(explode("\n", (string) $listing)) as $path) {
  if (!str_ends_with($path, '.php')) continue;
  $code = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg("$tag:$path"));
  $r = parse_code((string) $code, "$tag:$path");
  if ($r === null) continue;
  foreach ($r[0] as $fqcn => $c) {
    foreach ($c['members'] as $m) {
      $frozen["$fqcn::{$m['name']}"] = true;
      $frozen_names[$m['name']] = true;
    }
  }
}

// ---------------------------------------------------------------- hierarchy

/** @return list<string> all ancestor names (parents, interfaces, traits), transitive, excluding self. */
function ancestors(string $fqcn, array $all, array &$seen = []): array {
  $out = [];
  $c = $all[$fqcn] ?? null;
  if ($c === null) return [];
  foreach (array_merge($c['parents'], $c['interfaces'], $c['traits']) as $a) {
    if (isset($seen[$a])) continue;
    $seen[$a] = true;
    $out[] = $a;
    $out = array_merge($out, ancestors($a, $all, $seen));
  }
  return $out;
}

function external_declares(string $fqcn, string $kind, string $name): ?bool {
  static $cache = [];
  $key = "$fqcn::$kind::$name";
  if (array_key_exists($key, $cache)) return $cache[$key];
  if (str_starts_with($fqcn, 'TangibleDDD\\')) return $cache[$key] = null;
  try {
    if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
      return $cache[$key] = null;
    }
    $r = new ReflectionClass($fqcn);
    return $cache[$key] = $kind === 'method' ? $r->hasMethod($name) : $r->hasProperty($name);
  } catch (\Throwable) {
    return $cache[$key] = null;
  }
}

/** Ancestors that are not declared in any parsed corpus (vendor / WP / PHP). */
function external_ancestors(string $fqcn, array $all): array {
  $out = [];
  foreach (ancestors($fqcn, $all) as $a) {
    if (!isset($all[$a])) $out[] = $a;
  }
  return $out;
}

/**
 * Why a declaration is out of scope, or null. Checks self and every
 * ancestor against the 0.6.6 frozen set, external ancestors by reflection,
 * and the framework-name and PHPUnit rules.
 */
function out_of_scope(string $fqcn, array $m, array $all, array $frozen): ?string {
  $name = $m['name'];
  if (str_starts_with($name, '__')) return 'framework:magic';
  if (isset($frozen["$fqcn::$name"])) return 'frozen:0.6.6';
  foreach (ancestors($fqcn, $all) as $a) {
    if (isset($frozen["$a::$name"])) return "frozen:0.6.6 via $a";
  }
  // R2 splits: a new core base whose legacy 0.6 subclass carries the name
  // (IntegrationTranslator <- IntegrationListener) is frozen through it.
  foreach ($all as $d => $_) {
    if (isset($frozen["$d::$name"]) && in_array($fqcn, ancestors($d, $all), true)) {
      return "frozen:0.6.6 via subclass $d";
    }
  }
  $ext = external_ancestors($fqcn, $all);
  foreach ($ext as $a) {
    if (external_declares($a, $m['kind'], $name) === true) return "framework:$a";
  }
  if ($m['kind'] === 'method') {
    if (in_array($name, FRAMEWORK_ALWAYS, true)) return 'framework:spl';
    if ($ext && in_array($name, FRAMEWORK_WITH_EXTERNAL_ANCESTOR, true)) return 'framework:convention';
    $is_test_case = false;
    foreach ($ext as $a) {
      if ($a === 'PHPUnit\\Framework\\TestCase' || is_subclass_of($a, 'PHPUnit\\Framework\\TestCase')) {
        $is_test_case = true;
      }
    }
    if ($is_test_case) {
      if (str_starts_with($name, 'test') || in_array('PHPUnit\\Framework\\Attributes\\Test', $m['attrs'], true)) {
        return 'phpunit:test-method';
      }
      $providers = $all[$fqcn]['providers'] ?? [];
      foreach (ancestors($fqcn, $all) as $a) $providers = array_merge($providers, $all[$a]['providers'] ?? []);
      if (in_array($name, $providers, true)) return 'phpunit:data-provider';
    }
  }
  return null;
}

/** Library ancestors (excluding self) that declare the same member. */
function declaring_ancestors(string $fqcn, string $key, array $lib, array $all): array {
  $out = [];
  foreach (ancestors($fqcn, $all) as $a) {
    if (isset($lib[$a]['members'][$key])) $out[] = $a;
  }
  return $out;
}

function roots_of(string $fqcn, string $key, array $lib, array $all): array {
  $decl = declaring_ancestors($fqcn, $key, $lib, $all);
  if (!$decl) return [$fqcn];
  $roots = [];
  foreach ($decl as $a) {
    if (!declaring_ancestors($a, $key, $lib, $all)) $roots[] = $a;
  }
  return array_values(array_unique($roots));
}

// ---------------------------------------------------------------- inventory

$entries = [];
$excluded = [];
$implementers = []; // root::key => list of [fqcn, file, corpus]

foreach ($all as $fqcn => $c) {
  foreach ($c['members'] as $key => $m) {
    if ($m['visibility'] === 'private') continue;
    $violation = style_violation($m['name']);
    if ($violation === null) continue;
    $is_lib = isset($lib[$fqcn]);
    $oos = out_of_scope($fqcn, $m, $all, $frozen);
    if ($oos !== null) {
      if ($is_lib && $oos !== 'framework:magic') {
        $excluded[] = ['fqcn' => $fqcn, 'kind' => $m['kind'], 'name' => $m['name'], 'reason' => $oos,
          'file' => $c['file'] . ':' . $m['line']];
      }
      continue;
    }
    if ($is_lib) {
      $roots = roots_of($fqcn, $key, $lib, $all);
    } else {
      // Consumer class: only relevant as an implementation of a library root.
      $roots = [];
      foreach (declaring_ancestors($fqcn, $key, $lib, $all) as $a) {
        $roots = array_merge($roots, roots_of($a, $key, $lib, $all));
      }
      $roots = array_values(array_unique($roots));
    }
    foreach ($roots as $r) {
      if ($r === $fqcn) {
        $entries["$r::$key"] = [$fqcn, $m, $violation];
      } else {
        $implementers["$r::$key"][] = ['fqcn' => $fqcn, 'file' => $c['file'] . ':' . $m['line'],
          'corpus' => $c['corpus'] ?? 'library'];
      }
    }
  }
}

// Name collisions across entries (counts are name-matched).
$by_name = [];
foreach ($entries as $id => [$fqcn, $m]) $by_name[$m['kind'] . ':' . $m['name']][] = $fqcn;

$rows = [];
foreach ($entries as $id => [$fqcn, $m, $violation]) {
  $c = $lib[$fqcn];
  $area = area_for($c['file']);
  $counts = [];
  foreach ($parsed as $corpus => [, $usage]) {
    if ($m['kind'] === 'method') {
      $n = ($usage['method'][$m['name']] ?? 0);
    } else {
      $n = ($usage['property'][$m['name']] ?? 0) + ($m['promoted'] ? ($usage['named_arg'][$m['name']] ?? 0) : 0);
    }
    $counts[$corpus] = $n;
    $counts[$corpus . '_string_refs'] = $usage['string'][$m['name']] ?? 0;
  }
  $impl = $implementers[$id] ?? [];
  usort($impl, fn ($a, $b) => strcmp($a['fqcn'], $b['fqcn']));
  $shared = array_values(array_filter($by_name[$m['kind'] . ':' . $m['name']], fn ($f) => $f !== $fqcn));
  $rows[] = [
    'id' => "$fqcn::{$m['name']}" . ($m['kind'] === 'method' ? '()' : ''),
    'name' => $m['name'],
    'area' => $area,
    'declaring_fqcn' => $fqcn,
    'declaring_kind' => $c['kind'],
    'file' => $c['file'] . ':' . $m['line'],
    'kind' => $m['kind'],
    'visibility' => $m['visibility'],
    'static' => $m['static'],
    'promoted' => $m['promoted'],
    'violation' => $violation,
    'on_interface' => $c['kind'] === 'interface',
    'implementations' => $impl,
    'name_in_0_6_6' => isset($frozen_names[$m['name']]),
    'call_sites' => [
      'library' => $counts['library'],
      'library_tests' => $counts['library_tests'],
      'conformance' => $counts['conformance'],
      'txp' => $counts['txp'],
      'total' => $counts['library'] + $counts['library_tests'] + $counts['conformance'] + $counts['txp'],
    ],
    'string_refs' => [
      'library' => $counts['library_string_refs'],
      'library_tests' => $counts['library_tests_string_refs'],
      'conformance' => $counts['conformance_string_refs'],
      'txp' => $counts['txp_string_refs'],
    ],
    'shared_name_with' => $shared,
  ];
}

usort($rows, fn ($a, $b) => [array_search($a['area'], AREAS, true), $a['declaring_fqcn'], $a['kind'], $a['name']]
  <=> [array_search($b['area'], AREAS, true), $b['declaring_fqcn'], $b['kind'], $b['name']]);
usort($excluded, fn ($a, $b) => [$a['fqcn'], $a['name']] <=> [$b['fqcn'], $b['name']]);

$by_area = array_fill_keys(AREAS, 0);
$by_violation = [];
foreach ($rows as $r) {
  $by_area[$r['area']]++;
  $by_violation[$r['violation']] = ($by_violation[$r['violation']] ?? 0) + 1;
}
$by_reason = [];
foreach ($excluded as $e) {
  $k = explode(' ', explode(':', $e['reason'])[0] . ':' . (explode(':', $e['reason'])[1] ?? ''))[0];
  $by_reason[$k] = ($by_reason[$k] ?? 0) + 1;
}

$git = fn (string $dir) => trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse --short HEAD 2>/dev/null'));
$doc = [
  'generated_by' => 'tools/naming/inventory.php',
  'library_commit' => $git($root),
  'txp' => ['path' => $txp, 'commit' => $git($txp)],
  'frozen_tag' => $tag,
  'house_style' => 'lower snake_case (^[a-z][a-z0-9]*(_[a-z0-9]+)*$); accessors without get_ (get_by_* finders allowed)',
  'declaration_sources' => $decl_dirs,
  'call_site_corpora' => $corpora,
  'out_of_scope_rules' => [
    'frozen' => "member name declared on the same FQCN, or on any ancestor (parent/interface/trait), at $tag in ddd-src/ or ddd-wordpress/",
    'framework' => 'PHP magic (__*), SPL/JsonSerializable names, any member declared by a resolvable external (vendor) ancestor, and conventional Symfony/Messenger/Console/PHPUnit/Doctrine names when the class has an external ancestor',
    'phpunit' => 'test* / #[Test] methods and #[DataProvider] targets in PHPUnit\\Framework\\TestCase descendants',
    'private' => 'private members are not inventoried',
  ],
  'counting' => 'name-matched, not type-resolved; see shared_name_with. string_refs are exact string literals (callables, DI ->call()).',
  'areas' => AREAS,
  'total' => count($rows),
  'by_area' => $by_area,
  'by_violation' => $by_violation,
  'excluded_total' => count($excluded),
  'excluded_by_reason' => $by_reason,
  'entries' => $rows,
  'excluded' => $excluded,
];

$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
if ($out === null) {
  fwrite(STDOUT, $json);
  $summary = STDERR;
  $dest = 'stdout';
} else {
  @mkdir(dirname($out), 0777, true);
  file_put_contents($out, $json);
  $summary = STDOUT;
  $dest = rel($root, $out);
}
fwrite($summary, sprintf("[naming] %d entries (%d excluded) -> %s\n", count($rows), count($excluded), $dest));
foreach ($by_area as $a => $n) fwrite($summary, "  $a: $n\n");
