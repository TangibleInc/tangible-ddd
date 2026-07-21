# Consumer Modules Sidecar Spike Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove that classes in a sidecar namespace route through a sidecar container while retaining an existing host consumer's DDD config, prefix, persistence identity, and dashboard attribution.

**Architecture:** Add a module-only routing map beside the top-level consumer map in `ConsumerRegistry`. Module handles reuse the host `IDDDConfig`, use the module container getter, participate in longest-root ownership resolution, and remain absent from `all()`; process-hook integration stays deferred until rebasing onto the 0.6.1 compiled catalog.

**Tech Stack:** PHP 8.1+, PSR-11, League Tactician, PHPUnit 11, WordPress hook shims.

## Global Constraints

- Do not modify `ddd-wordpress/hooks.php`, `ddd-wordpress/cli/class-ddd-command.php`, `tangible-ddd.php`, `docs/wiring-a-consumer.md`, `docs/migration-0.2-to-0.3.md`, `ddd-src/Infra/DependencyInjection/`, or a `LongProcessCatalog` path.
- Do not mutate the host container. Resolve only explicit public host services through `service_for()`.
- A host must already be registered as a top-level consumer.
- A module namespace must be a strict whole-segment descendant of its most specific host consumer root.
- Module routes must not appear in `ConsumerRegistry::all()`.
- Use the host's exact `IDDDConfig` object and the module's container getter.
- Keep `ConsumerRegistry::owner_of(): ConsumerHandle` source-compatible.
- Preserve the intentional untracked `vendor` symlink.
- Follow red-green-refactor and commit coherent units.

---

### Task 1: Pin Module Routing Behavior With Failing Tests

**Files:**
- Create: `tests/Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Commands/RecordTrace.php`
- Create: `tests/Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Queries/FindTrace.php`
- Create: `tests/Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Domain/Events/TraceRecorded.php`
- Create: `tests/Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/IntegrationListeners/RecordTraceWhenTraceRecorded.php`
- Create: `tests/Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Process/SuperTraceProcess.php`
- Create: `tests/Unit/Consumers/ConsumerModuleRoutingTest.php`

**Interfaces:**
- Consumes: existing `CommandBusAware`, `QueryBusAware`, `Event::prefix()`, `IntegrationListener`, `ProcessRunner`, and `ConsumerRegistry::owner_of()` behavior.
- Produces: executable requirements for `ConsumerRegistry::consumer()`, `config_for()`, `service_for()`, `add_module()`, and `modules_for()`.

- [ ] **Step 1: Add sidecar fixtures under a real host-descendant namespace**

The command and query use the existing self-handling routing traits:

```php
namespace Tangible\LMS\Extension\SuperTrace\Application\Commands;

use TangibleDDD\Application\Commands\SelfHandlingCommand;

final class RecordTrace extends SelfHandlingCommand
{
    public function __construct(public readonly string $trace_id) {}
}
```

```php
namespace Tangible\LMS\Extension\SuperTrace\Application\Queries;

use TangibleDDD\Application\Queries\SelfHandlingQuery;

final class FindTrace extends SelfHandlingQuery
{
    public function __construct(public readonly string $trace_id) {}
}
```

The event and listener prove host-prefixed integration delivery back into the
module bus:

```php
namespace Tangible\LMS\Extension\SuperTrace\Domain\Events;

use TangibleDDD\Domain\Events\IntegrationEvent;

final class TraceRecorded extends IntegrationEvent
{
    public function __construct(public readonly string $trace_id) {}
}
```

```php
namespace Tangible\LMS\Extension\SuperTrace\Application\IntegrationListeners;

use Tangible\LMS\Extension\SuperTrace\Application\Commands\RecordTrace;
use Tangible\LMS\Extension\SuperTrace\Domain\Events\TraceRecorded;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Domain\Events\IIntegrationEvent;

final class RecordTraceWhenTraceRecorded extends IntegrationListener
{
    protected function get_event_class(): string { return TraceRecorded::class; }

    protected function get_command(IIntegrationEvent $event): ?ICommand
    {
        return new RecordTrace($event->trace_id);
    }
}
```

The process proves that a host runner can execute a module process whose step
command routes to the module container:

```php
namespace Tangible\LMS\Extension\SuperTrace\Application\Process;

use Tangible\LMS\Extension\SuperTrace\Application\Commands\RecordTrace;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

final class SuperTraceProcess extends LongProcess
{
    public function __construct(private readonly string $trace_id)
    {
        parent::__construct(null);
    }

    protected function record(): Result
    {
        return new Result(commands: [new RecordTrace($this->trace_id)]);
    }
}
```

- [ ] **Step 2: Write the focused registry and runtime tests**

Manually require the sidecar fixtures, reset `ConsumerRegistry` and WordPress
hook globals in `setUp()`, and use a small PSR-11 test container with a call log.
Cover:

```php
$hostConfig = new DDDConfig('tgbl_lms', 'Tangible\\LMS', 'test');
$host = ConsumerRegistry::add($hostConfig, fn () => $hostContainer, 'LMS');
$module = ConsumerRegistry::add_module(
    'tgbl_lms',
    'Tangible\\LMS\\Extension\\SuperTrace',
    fn () => $moduleContainer,
);

self::assertSame($hostConfig, $module->config());
self::assertSame($moduleContainer, ConsumerRegistry::owner_of(RecordTrace::class)->container());
self::assertSame('module-command', (new RecordTrace('trace-1'))->send());
self::assertSame('module-query', (new FindTrace('trace-1'))->send());
self::assertSame('tgbl_lms_integration_trace_recorded', TraceRecorded::integration_action());
self::assertSame(['tgbl_lms'], array_keys(ConsumerRegistry::all()));
self::assertSame(
    ['Tangible\\LMS\\Extension\\SuperTrace'],
    array_keys(ConsumerRegistry::modules_for('tgbl_lms')),
);
self::assertSame($hostConfig, ConsumerRegistry::config_for('tgbl_lms'));
self::assertSame($hostCorrelation, ConsumerRegistry::service_for(
    'tgbl_lms',
    CorrelationMiddleware::class,
));
```

Also prove:

- a module `CommandBus` built with the exact host correlation, transaction,
  and domain-publish middleware objects runs those objects in order before a
  module-local terminal middleware;
- `register_event_handlers()` can instantiate the module listener from the
  module container, and delivering a wrapped `TraceRecorded` sends a module
  command;
- `new ProcessRunner($hostConfig, new FakeProcessRepository())` can complete a
  `SuperTraceProcess` and dispatch its command to the module bus;
- a missing host fails;
- `Tangible\Notifications` cannot be registered as an LMS module;
- naming a broader host fails when a more specific consumer owns the module
  root; and
- the opaque host container receives only expected `get()` calls, exposes no
  builder mutation API, and the bridged objects are the exact host instances.

- [ ] **Step 3: Run the focused test and verify the API-missing failure**

Run:

```bash
vendor/bin/phpunit tests/Unit/Consumers/ConsumerModuleRoutingTest.php --testdox
```

Expected: FAIL with `Call to undefined method ConsumerRegistry::add_module()`.

- [ ] **Step 4: Commit the red test fixtures**

```bash
git add tests/Fakes/Sidecar tests/Unit/Consumers/ConsumerModuleRoutingTest.php
git commit -m "test: pin consumer module routing"
```

### Task 2: Implement the Module Routing Overlay

**Files:**
- Modify: `ddd-src/Infra/Consumers/ConsumerRegistry.php`
- Test: `tests/Unit/Consumers/ConsumerModuleRoutingTest.php`

**Interfaces:**
- Consumes: existing `ConsumerHandle(IDDDConfig, callable, ?string, ?string)` and top-level `ConsumerRegistry::$consumers`.
- Produces: `consumer(string): ConsumerHandle`, `config_for(string): IDDDConfig`, `service_for(string, string): mixed`, `add_module(string, string, callable): ConsumerHandle`, `modules_for(string): array`, and module-aware `owner_of(string): ConsumerHandle`.

- [ ] **Step 1: Add the module registration store and host lookup methods**

Use one structure so host attribution cannot drift from the handle:

```php
/** @var array<string, array{host_prefix: string, handle: ConsumerHandle}> */
private static array $modules = [];

public static function consumer(string $prefix): ConsumerHandle
{
    if (!isset(self::$consumers[$prefix])) {
        throw new \InvalidArgumentException("No registered DDD consumer has prefix \"$prefix\"");
    }

    return self::$consumers[$prefix];
}

public static function config_for(string $prefix): IDDDConfig
{
    return self::consumer($prefix)->config();
}

public static function service_for(string $prefix, string $service_id): mixed
{
    $container = self::consumer($prefix)->container();
    if (!method_exists($container, 'get')) {
        throw new \UnexpectedValueException(
            "DDD consumer \"$prefix\" container cannot resolve services"
        );
    }

    return $container->get($service_id);
}
```

- [ ] **Step 2: Validate and register module roots**

Normalize edge slashes, require a strict descendant, and require the named host
to be the longest top-level owner:

```php
public static function add_module(
    string $host_prefix,
    string $namespace_root,
    callable $di_getter,
): ConsumerHandle {
    $host = self::consumer($host_prefix);
    $root = trim($namespace_root, '\\');
    $host_root = trim($host->namespace_root(), '\\');

    if ($root === '' || $host_root === '' || !str_starts_with($root, $host_root . '\\')) {
        throw new \InvalidArgumentException(
            "Module namespace \"$namespace_root\" must be a strict descendant of host \"$host_root\""
        );
    }

    $nearest = self::top_level_owner_of($root);
    if ($nearest === null || $nearest->prefix() !== $host_prefix) {
        throw new \InvalidArgumentException(
            "Module namespace \"$root\" belongs to a more specific DDD consumer"
        );
    }

    $existing = self::$modules[$root] ?? null;
    if ($existing !== null && $existing['host_prefix'] !== $host_prefix) {
        throw new \InvalidArgumentException("Module namespace \"$root\" is already registered");
    }

    $handle = new ConsumerHandle($host->config(), $di_getter, $host->label(), $root);
    self::$modules[$root] = ['host_prefix' => $host_prefix, 'handle' => $handle];

    return $handle;
}
```

Extract the current whole-segment longest-match loop into a private helper that
accepts handles. `owner_of()` combines top-level handles with module handles;
the host validation helper scans top-level handles only.

- [ ] **Step 3: Add module enumeration and reset behavior**

```php
public static function modules_for(string $host_prefix): array
{
    self::consumer($host_prefix);

    $out = [];
    foreach (self::$modules as $root => $module) {
        if ($module['host_prefix'] === $host_prefix) {
            $out[$root] = $module['handle'];
        }
    }

    return $out;
}

public static function reset(): void
{
    self::$consumers = [];
    self::$modules = [];
}
```

Keep `all()` unchanged so it returns only `self::$consumers`.

- [ ] **Step 4: Run focused tests until green**

Run:

```bash
vendor/bin/phpunit tests/Unit/Consumers/ConsumerModuleRoutingTest.php --testdox
```

Expected: all focused tests pass; the host container resolves only the bridge
service IDs, returns the exact host instances, and exposes no mutation API.

- [ ] **Step 5: Run adjacent ownership and CQRS tests**

Run:

```bash
vendor/bin/phpunit \
  tests/Unit/WordPress/ConsumerOwnershipTest.php \
  tests/Unit/Consumers/RegistryResolvedBusTest.php \
  tests/Unit/Consumers/RegistryResolvedPrefixTest.php \
  tests/Unit/CQRS/SelfHandlingConsumerRoutingTest.php \
  --testdox
```

Expected: existing ownership, command, query, and event routing tests remain
green.

- [ ] **Step 6: Commit the implementation**

```bash
git add ddd-src/Infra/Consumers/ConsumerRegistry.php
git commit -m "feat: route consumer modules through sidecar containers"
```

### Task 3: Verify and Report the Spike

**Files:**
- Verify: all changed PHP and Markdown files.
- Create outside git: `/tmp/tangible-ddd-0.6.2-sidecar-spike-report.md`

**Interfaces:**
- Consumes: the committed spec, red test commit, and green implementation commit.
- Produces: exact test evidence, commit range, 0.6.1 intersection surface, and unresolved questions for the parent task.

- [ ] **Step 1: Lint changed PHP files**

Run `php -l` for every PHP file from:

```bash
git diff --name-only v0.6.0..HEAD -- '*.php'
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 2: Run the complete framework suite freshly**

```bash
vendor/bin/phpunit --testdox
```

Expected: zero failures. Existing PHPUnit deprecations may remain; record the
exact test/assertion/deprecation counts.

- [ ] **Step 3: Inspect final scope**

```bash
git diff --check v0.6.0
git status --short
git log --oneline --decorate v0.6.0..HEAD
```

Expected: only planned files plus the intentional untracked `vendor` symlink;
no changes to files reserved for the concurrent 0.6.1 implementation.

- [ ] **Step 4: Write the report**

The report must include:

- status (`DONE`, `DONE_WITH_CONCERNS`, `NEEDS_CONTEXT`, or `BLOCKED`);
- architecture comparison and selected invariants;
- exact commits and range from the common 0.6.1 design base;
- focused, adjacent, and full-suite commands with counts;
- proof that host config/module container routing works without host mutation;
- the sidecar loader contract: bundle a compatible `tangible/ddd:^0.6.2`,
  participate in version registration at `plugins_loaded:0`, wait for winner
  initialization at priority 1, then call `boot_module()` after host boot;
- exact files and functions that must be patched after rebasing onto 0.6.1;
- the documentation propagation checklist from the spec; and
- unresolved service-visibility, transaction-parity, dashboard-grouping, and
  live-process deactivation questions.
