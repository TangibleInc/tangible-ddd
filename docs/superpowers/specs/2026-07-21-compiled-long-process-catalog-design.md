# Compiled LongProcess Catalog Design

**Status:** Approved
**Target release:** Tangible DDD 0.6.1
**Date:** 2026-07-21

## Problem

Tangible DDD currently discovers process types at WordPress runtime by calling
`ContainerBuilder::findTaggedServiceIds('ddd.long_process')`. This works for
Cred and Datastream because they retain their `ContainerBuilder` after
compilation. It does not work for LMS and Quiz production releases, which load a
PHP container dumped by Symfony's `PhpDumper`. A dumped container extends the
runtime `Container`, not `ContainerBuilder`, and therefore has no service
definitions or tag-query API.

The existing guard silently skips process discovery when
`findTaggedServiceIds()` is unavailable. Consequently, a future LMS or Quiz
`LongProcess` could work with `WP_DEBUG=true` and fail to register its
`#[Awaits]` and `#[StartsOn]` hooks in the production release.

Symfony is honoring the YAML. `_instanceof` attaches `ddd.long_process` to each
registered service definition whose class extends `LongProcess`. The missing
operation is consuming that custom tag during compilation and materializing its
meaning into the dumped runtime container.

## Current Tagged Surfaces

Tangible DDD scans one framework tag:

- `ddd.long_process`: process type discovery for `#[Awaits]`, `#[StartsOn]`,
  and legacy `awaits:` tag attributes.

Current consumers using it:

- Cred: `DeadlineBoundCompletionProcess`.
- Datastream: `DestinationCutoverProcess`.
- LMS and Quiz: no process classes today.

Datastream separately scans `tangible_datastream.event_source`. That is a
plugin-local concern and is outside this patch because Datastream does not use a
dumped production container.

Commands, queries, event handlers, integration listeners, the outbox, and
migrations do not use `ddd.long_process` discovery and are not changed here.

## Decision

Introduce a consumer-scoped, compiled `LongProcessCatalog`.

1. Consumer YAML continues to tag registered `LongProcess` definitions with
   `ddd.long_process` through `_instanceof`.
2. A Tangible DDD compiler pass consumes those tags while the
   `ContainerBuilder` still owns its definitions.
3. The pass validates and de-duplicates the process classes, preserves legacy
   tag attributes, and registers a public `LongProcessCatalog` definition.
4. Symfony dumps that catalog as ordinary constructor data.
5. WordPress runtime registration reads the catalog and reflects each class's
   `#[Awaits]` and `#[StartsOn]` attributes.

The catalog stores process **types**, not process instances. `LongProcess`
objects carry journey-specific scalar state and are created from events or
rehydrated from persistence; the DI container must never instantiate them.

## Public Interfaces

```php
namespace TangibleDDD\Application\Process;

final class LongProcessCatalog
{
    /**
     * @param array<class-string<LongProcess>, list<array<string, mixed>>> $entries
     */
    public function __construct(private readonly array $entries = []) {}

    /**
     * @return array<class-string<LongProcess>, list<array<string, mixed>>>
     */
    public function all(): array;
}
```

```php
namespace TangibleDDD\Infra\DependencyInjection;

final class DDDCompilerPasses
{
    public static function register(ContainerBuilder $container): void;
}
```

`DDDCompilerPasses::register()` installs the internal
`LongProcessCatalogPass`. Consumers call it after loading their YAML and before
calling `compile()`.

## Runtime Compatibility

`register_hooks()` uses this order:

1. If the container provides `LongProcessCatalog`, register its entries.
2. Otherwise, if it exposes `findTaggedServiceIds()`, use the existing runtime
   discovery path.
3. Otherwise, retain the existing no-op behavior.

The fallback keeps 0.6.0-era dynamic consumers working without an immediate
migration. Consumers using dumped containers must register the compiler passes
to gain production parity.

The existing public `register_processes_from_container()` function remains for
backward compatibility. Shared class-registration logic is extracted so the
catalog and retained-builder paths cannot drift.

## Scaffolder Correction

The current scaffolder emits the `_instanceof` tag rule but does not create or
register `Application/Process`. Its promise that every process is automatically
tagged is therefore incomplete.

Generated consumers must additionally receive:

- `ddd-src/Application/Process/` and `.gitkeep`.
- A process namespace resource in `services.yaml` with `autowire: false`,
  `shared: false`, and `public: false`.
- A call to `DDDCompilerPasses::register()` in the generated DI bootstrap.

Process definitions are private because they exist only to participate in
compile-time discovery. The catalog retains their class names; runtime code
never resolves the definitions themselves.

## Consumer Rollout

After `v0.6.1` is published:

### Cred and Datastream

- Register DDD compiler passes before their eager `compile()` calls.
- Keep their existing `_instanceof` tag rules and process registrations.
- Make process definitions private.
- Require `tangible/ddd:^0.6.1`.
- Replace the misleading local path version `0.2.9999` with `0.6.1`.
- Datastream raises its runtime minimum from `0.6.0` to `0.6.1` once it calls
  the new compiler-pass API.

### LMS and Quiz

- Register DDD compiler passes in the development container construction.
- Register them in the shared `bin/build-php` release compiler.
- Make integration-test container builders use the same configuration path.
- Require `tangible/ddd:^0.6.1` and update lockfiles after the tag exists.
- Regenerate production containers during the normal build; generated
  `var/container/CompiledContainer.php` files remain uncommitted artifacts.
- Do not add empty process resources until a plugin introduces its first
  process.

`tangible/wp-rest` may retain its broad `<1.0.0` compatibility constraint.
LMS and Quiz do not need `SelfExecutingCommandMiddleware` unless they adopt
self-handling commands or queries.

## Versioning

This is a patch release because it makes an existing documented behavior work
with a supported consumer container shape without changing the process model or
consumer-facing YAML contract.

All loader identity surfaces advance together to `0.6.1`:

- WordPress plugin header.
- `TANGIBLE_DDD_VERSION` constant.
- Registry version literal.
- Versioned registration and initialization function slugs.

The existing `LoaderIdentityTest` remains the release guard.

## Verification

The central regression test must use Symfony's real `PhpDumper`:

1. Register a fake `LongProcess` with a required scalar constructor argument.
2. Apply `ddd.long_process` through `_instanceof` or an equivalent definition.
3. Register the DDD compiler passes.
4. Compile and dump the container.
5. Load the generated runtime container.
6. Assert its `LongProcessCatalog` contains the process class and tag metadata.
7. Assert the process constructor was never invoked.
8. Run process hook registration and assert `#[Awaits]` and `#[StartsOn]`
   actions were registered.

Additional coverage must pin empty catalogs, duplicate classes, malformed tag
targets, legacy `awaits:` attributes, retained-builder fallback, scaffolder
output, and loader identity.

## Non-Goals

- No late/runtime side-plugin process registration API. That capability is
  reserved for Tangible DDD 0.6.2.
- No sidecar or Super Trace module implementation.
- No changes to process persistence, schemas, execution, compensation, or
  correlation behavior.
- No changes to BehaviourWorkflow discovery or execution.
- No conversion of LMS/Quiz commands or queries to self-handling forms.
- No hand-editing generated production containers.
