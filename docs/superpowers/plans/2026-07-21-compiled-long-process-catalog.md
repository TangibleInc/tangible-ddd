# Compiled LongProcess Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compile `ddd.long_process` tags into a runtime `LongProcessCatalog` so retained and dumped Symfony containers register identical process hooks.

**Architecture:** A Symfony compiler pass converts tagged process definitions into an immutable, public catalog containing class names and legacy tag attributes. WordPress registration prefers that catalog and retains the current `ContainerBuilder` scan as a compatibility fallback. Stateful process objects are never instantiated by DI.

**Tech Stack:** PHP 8.1+, Symfony DependencyInjection 7.4, Symfony PhpDumper, PHPUnit 11, WordPress hook shims.

## Global Constraints

- Target release is exactly `0.6.1`; every loader identity surface must agree.
- Preserve the existing `ddd.long_process` YAML contract.
- Preserve `register_processes_from_container()` as a callable compatibility API.
- Preserve legacy `awaits:` tag attributes as well as `#[Awaits]` and `#[StartsOn]` attributes.
- Never instantiate a `LongProcess` during compilation, catalog lookup, or hook registration.
- Dynamic 0.6.0-style consumers without the compiler pass must continue to work through the retained-builder fallback.
- Dumped containers configured with the compiler pass must not require `findTaggedServiceIds()` at runtime.
- Runtime side-plugin registration is out of scope and reserved for 0.6.2.
- Do not change database schemas, process execution semantics, BehaviourWorkflow, CQRS middleware, or generated production artifacts.
- Follow red-green-refactor and commit each completed task.

---

### Task 1: Compile Tagged Process Types Into a Catalog

**Files:**
- Create: `ddd-src/Application/Process/LongProcessCatalog.php`
- Create: `ddd-src/Infra/DependencyInjection/LongProcessCatalogPass.php`
- Create: `ddd-src/Infra/DependencyInjection/DDDCompilerPasses.php`
- Create: `tests/Unit/DependencyInjection/LongProcessCatalogPassTest.php`

**Interfaces:**
- Consumes: Symfony `ContainerBuilder`, `Definition`, and `CompilerPassInterface`; existing `LongProcess`.
- Produces: `LongProcessCatalog::__construct(array $entries = [])`, `LongProcessCatalog::all(): array`, and `DDDCompilerPasses::register(ContainerBuilder $container): void`.

- [ ] **Step 1: Write failing catalog/compiler-pass tests**

Cover an empty catalog, one tagged process, legacy tag attributes, duplicate class definitions merging without duplicate catalog keys, and a tagged non-`LongProcess` failing compilation. Use a fake process whose constructor requires a scalar to prove the pass inspects definitions without resolving services.

```php
$builder = new ContainerBuilder();
$builder->register(RequiredConstructorProcess::class)
    ->setAutowired(false)
    ->setPublic(false)
    ->addTag('ddd.long_process', ['awaits' => [FakeResolvedEvent::class]]);

DDDCompilerPasses::register($builder);
$builder->compile();

$catalog = $builder->get(LongProcessCatalog::class);
self::assertSame(
    [RequiredConstructorProcess::class => [[
        'awaits' => [FakeResolvedEvent::class],
    ]]],
    $catalog->all(),
);
```

- [ ] **Step 2: Run the focused tests and verify the missing-class failure**

Run:

```bash
vendor/bin/phpunit tests/Unit/DependencyInjection/LongProcessCatalogPassTest.php --testdox
```

Expected: FAIL because the three new framework classes do not exist.

- [ ] **Step 3: Implement the minimal catalog, pass, and registration facade**

The pass must resolve each definition's effective class, validate it with
`is_subclass_of($class, LongProcess::class)`, merge repeated tag attribute
arrays by class, and register `LongProcessCatalog` as a public ordinary service.
Do not store `Reference` objects to the process definitions.

```php
final class LongProcessCatalog
{
    public function __construct(private readonly array $entries = []) {}

    public function all(): array
    {
        return $this->entries;
    }
}
```

```php
final class DDDCompilerPasses
{
    public static function register(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new LongProcessCatalogPass());
    }
}
```

- [ ] **Step 4: Run the focused tests until green**

Run the command from Step 2. Expected: all focused tests pass and the scalar-required process constructor remains untouched.

- [ ] **Step 5: Commit Task 1**

```bash
git add ddd-src/Application/Process/LongProcessCatalog.php ddd-src/Infra/DependencyInjection tests/Unit/DependencyInjection/LongProcessCatalogPassTest.php
git commit -m "fix: compile long process tags into a catalog"
```

### Task 2: Register Process Hooks From Either Catalog or Builder

**Files:**
- Modify: `ddd-wordpress/hooks.php:61-80,215-282`
- Modify: `tests/Unit/WordPress/BootTest.php:116-199`

**Interfaces:**
- Consumes: `LongProcessCatalog::all()` from Task 1 and existing `ProcessRunner` registration methods.
- Produces: one shared process-entry registration path used by both compiled-catalog and retained-builder discovery.

- [ ] **Step 1: Add failing runtime tests**

Add tests proving that `register_hooks()`:

- Uses `LongProcessCatalog` when the container lacks `findTaggedServiceIds()`.
- Registers both `#[Awaits]` and `#[StartsOn]` hooks from catalog entries.
- Still discovers tags from a retained builder-shaped stub with no catalog.
- Preserves a legacy `awaits:` event supplied in catalog tag attributes.

The opaque-container stub must implement only `getServiceIds()`, `has()`, and
`get()`; it must not expose `findTaggedServiceIds()`.

- [ ] **Step 2: Run the focused Boot tests and verify failure**

```bash
vendor/bin/phpunit tests/Unit/WordPress/BootTest.php --testdox
```

Expected: the new opaque-container/catalog test fails because `register_hooks()` still skips discovery.

- [ ] **Step 3: Extract shared entry registration and prefer the catalog**

Keep `register_processes_from_container()` intact as a compatibility wrapper.
Extract logic equivalent to:

```php
function register_process_entries(
    IDDDConfig $config,
    ProcessRunner $runner,
    array $entries,
): void {
    foreach ($entries as $class => $tags) {
        // Existing subclass validation, #[Awaits], #[StartsOn], and legacy awaits handling.
    }
}
```

At boot, query the catalog first using runtime-container APIs (`has()` and
`get()`). Only use `findTaggedServiceIds()` when no catalog is present.

- [ ] **Step 4: Run Boot tests and the complete unit suite**

```bash
vendor/bin/phpunit tests/Unit/WordPress/BootTest.php --testdox
vendor/bin/phpunit --testdox
```

Expected: Boot tests pass; complete suite reports 437 existing tests plus the new tests, with zero failures.

- [ ] **Step 5: Commit Task 2**

```bash
git add ddd-wordpress/hooks.php tests/Unit/WordPress/BootTest.php
git commit -m "fix: discover processes through the compiled catalog"
```

### Task 3: Prove Real PhpDumper Compatibility

**Files:**
- Create: `tests/Unit/DependencyInjection/DumpedLongProcessCatalogTest.php`

**Interfaces:**
- Consumes: Task 1 compiler integration and Task 2 runtime catalog path.
- Produces: a regression test exercising an actual generated Symfony runtime container.

- [ ] **Step 1: Write the dumped-container regression test**

Build a real `ContainerBuilder`, register the DDD compiler passes and a tagged
fake process with a required scalar constructor, compile it, dump it with
`PhpDumper`, load the generated class from a unique temporary file/namespace,
and instantiate it.

Assert:

```php
self::assertFalse(method_exists($runtimeContainer, 'findTaggedServiceIds'));
self::assertTrue($runtimeContainer->has(LongProcessCatalog::class));
self::assertArrayHasKey(
    RequiredConstructorProcess::class,
    $runtimeContainer->get(LongProcessCatalog::class)->all(),
);
self::assertSame(0, RequiredConstructorProcess::$constructions);
```

Then pass the runtime container through the process-registration path and
assert the fake process's awaited and ignition actions are present while the
constructor count remains zero. Always remove the temporary file in `finally`.

- [ ] **Step 2: Verify the test fails without complete dump support**

```bash
vendor/bin/phpunit tests/Unit/DependencyInjection/DumpedLongProcessCatalogTest.php --testdox
```

Expected: FAIL if the catalog is private, contains references, or runtime registration still depends on `ContainerBuilder`.

- [ ] **Step 3: Make only the changes required by the real dump**

Correct service visibility, serializable constructor data, or runtime API assumptions exposed by Step 2. Do not introduce a runtime extension registry.

- [ ] **Step 4: Run the focused and complete suites**

```bash
vendor/bin/phpunit tests/Unit/DependencyInjection/DumpedLongProcessCatalogTest.php --testdox
vendor/bin/phpunit --testdox
```

Expected: dumped-container test and complete suite pass with zero failures.

- [ ] **Step 5: Commit Task 3**

```bash
git add tests/Unit/DependencyInjection/DumpedLongProcessCatalogTest.php ddd-src ddd-wordpress/hooks.php
git commit -m "test: cover dumped container process discovery"
```

### Task 4: Repair Generated Consumer Wiring

**Files:**
- Modify: `ddd-wordpress/cli/class-ddd-command.php:155-275,292-345,363-487`
- Modify: `tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php`

**Interfaces:**
- Consumes: `DDDCompilerPasses::register()` from Task 1.
- Produces: newly scaffolded consumers with a process directory, process resource, tag rule, and compiler-pass registration.

- [ ] **Step 1: Add failing scaffolder-conformance assertions**

Assert that generated output:

- Creates `ddd-src/Application/Process/.gitkeep`.
- Registers `{$namespace}\Application\Process\` as a YAML resource.
- Sets that resource to `autowire: false`, `shared: false`, and `public: false`.
- Calls `DDDCompilerPasses::register($container_builder)` before compilation.
- Retains `_instanceof LongProcess -> ddd.long_process`.

- [ ] **Step 2: Run focused tests and verify failure**

```bash
vendor/bin/phpunit tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php --testdox
```

Expected: FAIL because the current scaffolder emits the tag rule without creating or registering the Process directory.

- [ ] **Step 3: Update the directory, file, DI-index, and services templates**

Add the missing directory and `.gitkeep`, import/register
`DDDCompilerPasses`, and emit this resource alongside other application
resources:

```yaml
Acme\Application\Process\:
  resource: '../../ddd-src/Application/Process'
  autowire: false
  shared: false
  public: false
```

- [ ] **Step 4: Run scaffold and full tests**

```bash
vendor/bin/phpunit tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php --testdox
vendor/bin/phpunit --testdox
```

Expected: focused and complete suites pass with zero failures.

- [ ] **Step 5: Commit Task 4**

```bash
git add ddd-wordpress/cli/class-ddd-command.php tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php
git commit -m "fix: scaffold compiled process discovery"
```

### Task 5: Document and Stamp Tangible DDD 0.6.1

**Files:**
- Modify: `tangible-ddd.php`
- Modify: `docs/wiring-a-consumer.md:189-225`
- Modify: `docs/migration-0.2-to-0.3.md`
- Test: `tests/Unit/Loader/LoaderIdentityTest.php`

**Interfaces:**
- Consumes: completed behavior from Tasks 1-4.
- Produces: release identity `0.6.1` and exact consumer migration instructions.

- [ ] **Step 1: Update documentation**

Document the catalog-first/fallback runtime, exact
`DDDCompilerPasses::register()` placement, required process resource, dumped
container support, and the post-tag consumer rollout. State explicitly that
late side-plugin registration is deferred to 0.6.2.

- [ ] **Step 2: Advance every loader identity surface**

Update the plugin header, constant, registry literal, callback, and guarded
function names from `0.6.0`/`_0_6_0` to `0.6.1`/`_0_6_1`. Do not leave aliases
with the old function slug.

- [ ] **Step 3: Run identity and full tests**

```bash
vendor/bin/phpunit tests/Unit/Loader/LoaderIdentityTest.php --testdox
vendor/bin/phpunit --testdox
```

Expected: loader identity passes and the complete suite has zero failures.

- [ ] **Step 4: Commit Task 5**

```bash
git add tangible-ddd.php docs/wiring-a-consumer.md docs/migration-0.2-to-0.3.md
git commit -m "chore: prepare tangible ddd 0.6.1"
```

### Task 6: Final Verification and Consumer Rollout Handoff

**Files:**
- Verify only; do not modify generated consumer containers.

**Interfaces:**
- Consumes: all previous tasks.
- Produces: a reviewed 0.6.1 framework branch and an exact post-tag consumer checklist.

- [ ] **Step 1: Run syntax checks over changed PHP files**

```bash
git diff --name-only v0.6.0 -- '*.php'
```

Run `php -l` on every listed PHP file. Expected: no syntax errors.

- [ ] **Step 2: Run the complete framework suite freshly**

```bash
vendor/bin/phpunit --testdox
```

Expected: zero failures. Existing PHPUnit deprecations may remain but no new warnings or errors may be introduced.

- [ ] **Step 3: Inspect scope and identity**

```bash
git diff --check v0.6.0
git status --short
git log --oneline --decorate v0.6.0..HEAD
```

Expected: no whitespace errors; only planned files changed; task commits visible.

- [ ] **Step 4: Write the implementation report**

Record commits, exact test counts, any existing deprecations, and this post-tag rollout order:

1. Publish Tangible DDD `v0.6.1`.
2. Update Cred and Datastream compile setup plus dependency metadata.
3. Update LMS/Quiz development and `bin/build-php` compile setup.
4. Update LMS/Quiz constraints and locks against the published tag.
5. Build both release containers and verify process catalogs under `WP_DEBUG=false`.
