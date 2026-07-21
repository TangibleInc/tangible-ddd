---
name: tangible-ddd
description: Use when implementing or reviewing domain models, commands, queries, events, behaviour workflows, long processes, Tangible DDD containers, or consumer modules in a WordPress plugin.
---

# Tangible DDD

Use the installed framework as the source of truth. Tangible DDD evolves
quickly, and examples copied from another consumer may describe a different
release or persistence stack.

## Establish the installed contract

Before changing a consumer:

1. Check its `composer.lock` or run `composer show tangible/ddd` from that
   plugin. Locate the installed package with `composer show --path tangible/ddd`.
2. On a multi-plugin WordPress site, remember that the newest registered copy
   wins. At runtime, `Tangible_DDD_Versions::instance()->winner()` reports the
   active version and path.
3. Read that copy's source and tests. Then read its
   [wiring guide](../../../docs/wiring-a-consumer.md),
   [consumer design interview](../../../docs/consumer-design-interview.md),
   [module guide](../../../docs/consumer-modules.md), and
   [release ledger](../../../docs/migration-0.2-to-0.3.md).
4. Finally inspect the consumer's DI YAML, container build path, architecture
   docs, and tests. Local guidance may specialize the framework; it does not
   silently replace it.

## Hard invariants

- Every application state change enters through the command bus. A command
  handler may call aggregates, repositories, and domain services; those are
  collaborators inside the command's unit of work, not alternate write doors.
- One command represents one intent. A command or synchronous domain-event
  handler never sends another command. Do synchronous work directly or announce
  an integration event for a later unit of work.
- Queries do not mutate state. Their bus deliberately has no correlation/audit,
  transaction, or domain-event publication middleware.
- Domain events are synchronous and transactional. Integration events cross a
  consistency or time boundary through the outbox.
- Integration listeners translate an integration event into a command or
  return `null`. They contain no domain work.
- A `LongProcess` coordinates commands across time. Its steps do not start
  another process or publish integration events directly.
- Consumer identity comes from an explicit prefix and namespace root. Never
  infer ownership from a broad namespace, plugin activation, or table names.

## Design dialogue before code

When a requested behavior leaves authority, invariants, transaction scope,
time boundaries, retry, orchestration, or consumer ownership unclear, do not
silently choose. Ask the developer one high-information question at a time and
maintain a provisional decision ledger.

- Explain why a question matters when its consequence is not obvious.
- Revise the provisional model when answers conflict; surface the conflict.
- Stop when the material boundaries are known, summarize the proposed model,
  and obtain approval before implementation.
- Do not force an interview onto a narrow change whose intent and boundaries
  are already exact.

Use the [consumer design interview](../../../docs/consumer-design-interview.md)
for the question funnel, adversarial review, and handoff format.

## Choose the construct

| Need | Construct |
| --- | --- |
| Protect invariants for one consistency boundary | Aggregate method |
| Business decision involving several aggregates or repositories | Domain service, invoked inside a command handler |
| Express one state-changing intention | Command |
| Read and return data without mutation | Query |
| React synchronously inside the same transaction | Domain event handler |
| React later, across a boundary, with retry and causation | Integration event plus `IntegrationListener` |
| Run a stored/configurable sequence over work items | `BehaviourWorkflow` routine |
| Model a named lifecycle that waits, resumes, schedules, or compensates | `LongProcess` |

Do not introduce an abstraction only to rename a handler. The distinction must
change ownership, lifecycle, persistence, or execution semantics.

## Commands and queries

Both handler shapes are supported.

### Separate handler

- A command implements `ICommand` and normally uses `CommandBusAware` so
  `send()` resolves the owning consumer's command bus.
- Its convention-named handler implements `ICommandHandler`; `handle()` is
  `void` and delegates domain work as needed.
- A query implements `IQuery`, normally uses `QueryBusAware`, and resolves its
  convention-named `IQueryHandler` on `tactician.query_bus`.

Keep this shape when the message is a stable contract, the handler has several
dependencies, or the separation improves testing and naming.

### Self-handling

- Extend `SelfHandlingCommand` and define a protected `handle()` method.
- Extend `SelfHandlingQuery` for the read-side equivalent.
- Dependencies are method-injected from the owning consumer container by
  `SelfExecutingCommandMiddleware`.

Self-handling removes a thin one-message/one-handler pair; it does not bypass
middleware. A command normally returns `void`. A scalar or DTO verdict may be
returned for transport steering, but never a domain object, and downstream
domain behavior must not depend on it. A query returns its read result.

The supported command chain is:

```text
CorrelationMiddleware
  -> TransactionMiddleware
  -> DomainEventsPublishMiddleware
  -> SelfExecutingCommandMiddleware
  -> CommandHandlerMiddleware
```

The query chain contains only the self-executing terminal followed by the
normal query-handler terminal.

## Event boundary

### Domain event

Extend `DomainEvent`, implement `payload()`, and record the event on an
aggregate with `event()`. The aggregate repository collects it into
`EventsUnitOfWork`; publication remains inside the command transaction.

Use a synchronous event handler only when the reaction must commit atomically
with the raising command. It may call repositories or domain services directly,
but it may not dispatch a command.

`EventsUnitOfWork` is container-managed state owned by the command middleware.
Inject it or resolve the live container service; never cache it in a static
consumer facade. The middleware must reset, seal, and drain the same instance
repositories record into. During the sealed transitive drain, only an event
implementing `IAnnouncesIntegration` may be newly recorded; both scalar
self-publishers and rich events with a scalar twin satisfy that contract.

### Integration event

An integration record implements `IIntegrationEvent`. Its constructor is its
wire schema: parameter names are payload keys, and values must round-trip
through `integration_payload()` and `from_payload()`.

Use one of three forms:

1. A scalar `IntegrationEvent` published directly on
   `IIntegrationEventBus`.
2. A scalar domain event that also implements `IIntegrationEvent` and
   `IAnnouncesIntegration`, using `IntegrationBehaviour` to announce itself.
3. A rich domain event implementing `IAnnouncesIntegration` whose
   `to_integration()` returns a separate scalar twin, normally under an
   `Integration` subnamespace.

Treat published action names, constructor parameter names, and payload types as
cross-plugin contracts. Freeze or deliberately evolve both serialization
directions when another plugin subscribes.

Consumers react with subclasses of
`TangibleDDD\Application\EventHandlers\IntegrationListener` registered under
their `Application\IntegrationListeners` service namespace. Its only policy is
`get_event_class()` plus `get_command()`.

## Correlation and tracing

`Correlation` is the runtime facade. `TraceContext` is the immutable propagated
value containing the correlation ID, current cause, and story sequence.

- Inside a guaranteed command/event/process scope, read
  `Correlation::current()`.
- When flat execution is legitimate, use `Correlation::peek()` to avoid
  minting a root merely by observing it.
- Use `Correlation::within($context, $work)` only when implementing a real
  boundary adapter that restores a propagated context. The framework's normal
  buses, outbox, listeners, and process runner already establish scopes.
- Do not store mutable global correlation state or manually copy IDs between
  ordinary handlers. Preserve the integration envelope instead.

Commands, integration publications, process starts/wakes, and workflow work
write causation edges into consumer-scoped records. The dashboard assembles a
trace across plugins because the correlation travels with integration events;
it does not require one shared audit table.

## Behaviour workflow routines

Use `BehaviourWorkflow` for a configurable sequence whose behaviour kinds,
work items, batching, retry, waiting, or forking are persisted. The current
runtime is deliberately paired with a consumer `WorkflowHandler` command
handler:

- `get_workflows()` selects persisted workflow aggregates for the command.
- `generate_work_items()` deterministically creates the step ledger.
- `execute_one()` enacts one configured behaviour, directly or through a
  domain service.
- `reschedule()` chooses the consumer's continuation mechanism.

Workflow state belongs in `BehaviourWorkflow` and its repositories. Handler
properties are request-local convenience, not durable session state. Do not
turn every retry, mail send, or internal behaviour into a nested command merely
because commands are the outer write boundary. The driving command owns one
workflow pass; the paired policy enactor performs the routine inside it.

Use a `LongProcess` instead when the lifecycle itself is developer-authored and
its purpose is to coordinate distinct commands over time.

## Long processes

A `LongProcess` is persistent lifecycle state, never a service resolved for
execution from the container. Protected step methods return `Result` values
containing typed lifecycle payload, commands, an await mechanism, scheduling,
or checkpoints.

- `#[StartsOn(Event::class)]` plus `from_event()` is the normal reactive start.
- `#[Awaits(Event::class)]` registers wake routes.
- `#[Compensates('step_name')]` associates compensation.
- Use `AwaitEvent` for one arrival and `AwaitAll` for durable fan-in.
- A process may return commands because coordinating separate units of work is
  its purpose. The resulting domain work announces any further integration
  events.

For retained and dumped Symfony containers, register process classes as
private discovery definitions tagged `ddd.long_process`. Call
`DDDCompilerPasses::register($container_builder)` after loading all service
resources and before `compile()` in development, tests, and production builds.
The pass materializes `LongProcessCatalog`; runtime registration reads the
catalog without constructing process objects or querying tags from a dumped
container.

## Touches and Biography

Annotate a lifecycle-declaring event with repeatable
`#[Touches(Op::Created|Updated|Deleted, AggregateClass::class, id: '...')]`.
The outbox/event bookkeeping projects these declarations into the consumer's
touches table. The dashboard Biography is a read model over those rows, not an
event store or write authority.

Use class references in code. If an aggregate with recorded touches is renamed,
override `canonical_name()` to preserve its historical at-rest name. Run
`IntegrationConformance` so invalid aggregate references or unresolved IDs fail
in CI rather than disappearing from Biography.

## Top-level consumers and modules

A top-level consumer owns a prefix, config, tables, container, workers,
migrations, and dashboard entry. It calls `TangibleDDD\WordPress\boot()` after
the winning framework initializes, normally at `plugins_loaded:10`.

A consumer module contributes a strict descendant namespace through a separate
compiled container while sharing its host's exact runtime identity. A sidecar
plugin is one packaging form. It calls `boot_module()` at
`plugins_loaded:30`, after the host is registered. Module runtime wiring runs
at `init:3`, after host hooks at `init:2`.

Module rules:

- Bind `IDDDConfig` with `ConsumerRegistry::config_for($host_prefix)`.
- Import the host's actual stateful middleware, outbox, unit of work, and
  `ProcessRunner` with `ConsumerRegistry::service_for()`. Do not construct
  lookalikes.
- Keep terminal command/query resolution and module dependencies in the module
  container.
- Compile the module's own `LongProcessCatalog`; `boot_module()` validates and
  registers its entries on the exact host runner.
- Never mutate a retained, compiled, or dumped host container.
- `ConsumerRegistry::all()` remains top-level only. Use `modules_for()` for the
  routing overlay.
- Before removing a module, drain, migrate, fail, or preserve compatibility for
  active process rows containing its class names.

Use the [consumer module guide](../../../docs/consumer-modules.md) for the exact
factory services, load order, failure modes, and dumped-container tests.

## Removed surfaces

Do not reintroduce old names found in historical plans:

| Removed surface | Current contract |
| --- | --- |
| `CorrelationContext` | `Correlation` facade plus immutable `TraceContext` |
| `CommandAuditMiddleware` | Audit is owned by `CorrelationMiddleware` |
| `AsyncWordPressActionHandler` | Integration event, listener, then command |
| `TransportEnvelope` | `IntegrationEnvelope` |
| WordPress-namespace consumer registry aliases | `TangibleDDD\Infra\Consumers` classes |
| Runtime tag scanning as the production path | Compiled `LongProcessCatalog` |

Historical docs may preserve these names as evidence. They are not migration
examples unless the current release ledger explicitly says so.

## Finish checklist

- Installed version and winning runtime copy verified.
- State-changing entry points use the command bus; query paths stay read-only.
- Command, event, routine, and long-process boundaries match their lifecycles.
- Container YAML and every build path register the current middleware/catalog
  contracts.
- Integration wire changes have publisher and subscriber tests.
- Process discovery is tested against the actual dumped container, not only a
  `ContainerBuilder`.
- Consumer modules preserve host object identity and never appear as a second
  persistence consumer.
- Consumer suite, framework conformance, migrations, and dashboard smoke pass.

Start with [the package README](../../../README.md) for the capability map and
[the docs index](../../../docs/README.md) for current versus historical status.
