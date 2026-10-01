# Modeling rules

**Status:** CURRENT for the 0.6.x line.

This is the checkable list of rules a Tangible DDD consumer follows. Each rule
has a stable ID, the reason it exists, and how it is enforced. Humans read it
in review, coding agents read it before they write code, and static analysis
reports violations by the same IDs.

The [canonical agent skill](../.claude/skills/tangible-ddd/SKILL.md) explains
how to choose between the framework's constructs. This document states what
must hold once the choice is made. When they disagree, follow the source
order in the [documentation map](README.md) and fix whichever is wrong.

## How to read a rule

| Field | Meaning |
| --- | --- |
| ID | Stable identifier, cited by tools and reviews. Never reused. |
| Rule | What must hold. |
| Why | The failure the rule prevents. |
| Enforced by | Where a violation is caught, from the list below. |

Enforcement, strongest first:

- **Runtime**: the framework refuses at runtime, whatever the consumer does.
- **Phan**: a plugin in `tangible/phan-ddd-plugins` reports it during static
  analysis. *Planned* means the rule is specified here but the check does not
  exist yet.
- **Conformance**: a framework test helper that a consumer runs in CI, such as
  `IntegrationConformance`.
- **Review**: judgment; checked by a human or a review agent using this
  document as the rubric.

A consumer adopting a new Phan rule fixes its existing violations. Where a fix
needs planning of its own, the violation goes into a Phan baseline with the
reason recorded next to it, and is fixed from there. New code never adds to a
baseline.

## Layers

A consumer's code sits under its namespace root (for example `Acme\Orders`)
in these layers:

| Layer | Namespace | Contains |
| --- | --- | --- |
| Domain | `<root>\Domain` | Aggregates, entities, value objects, domain events, domain services, repository interfaces |
| Application | `<root>\Application` | Commands, queries, their handlers, event handlers, integration listeners, long processes, application services |
| Infra | `<root>\Infra` | Repository implementations, persistence mapping, framework wiring |
| Adapters | everything else | The ways in: REST endpoints, admin screens, CLI commands, WordPress hook callbacks |

The framework fixes the first three names. A consumer names its adapter
namespaces in its local overlay (see [Consumer overlays](#consumer-overlays)).

A class is a **domain service** when it implements
`TangibleDDD\Domain\Services\IDomainService`. Where it lives inside the Domain
layer does not matter.

### DDD-L1: The domain depends on nothing outside itself

Code in the Domain layer references only the consumer's own Domain code, the
`TangibleDDD\Domain` contracts, and PHP itself. It does not reference the
consumer's Application, Infra, or adapter code, WordPress functions or
classes, or persistence libraries.

- **Why:** domain rules must be testable without WordPress or a database, and
  every other layer depends on the domain. A domain that reaches outward
  couples the most stable code to the least stable.
- **Enforced by:** Phan (planned).

### DDD-L2: Only domain services use repositories

Aggregates, entities, value objects, and domain events never depend on a
repository or on another service. Domain services, the classes implementing
`IDomainService`, may depend on repository interfaces.

- **Why:** an aggregate protects the invariants of one consistency boundary
  using the state it holds. Loading other state belongs to the command handler
  or a domain service, inside the command's unit of work.
- **Enforced by:** Phan (planned).

### DDD-L3: Repository interfaces are domain contracts

A repository interface lives in the Domain layer and its signatures use only
domain types, scalars, and framework domain types. Its implementation lives in
Infra.

- **Why:** an interface that names a persistence type makes the domain depend
  on how it is stored, which DDD-L1 exists to prevent.
- **Enforced by:** Phan (planned).

### DDD-L4: The application layer does not depend on adapters

Application code references the Domain layer, the framework, and its own
layer. It does not reference adapter namespaces.

A query returns an application read model: a DTO defined in the Application
layer. Adapters serialize it or map it to their own format; they do not
define the types query handlers return.

- **Why:** the same use case must be reachable from REST, the CLI, a WordPress
  hook, or a test without dragging one transport's types into the others.
- **Enforced by:** Phan (planned).

### DDD-L5: Adapters go through the buses

Adapter code changes application state only by sending commands, and reads
it only by running queries. It never references a repository, and never calls
an aggregate's methods directly.

- **Why:** every application state change enters through the command bus,
  where correlation, audit, transactions, and event publication happen; a
  write that bypasses it bypasses all four. Reads go through queries so each
  use case has one entry point that every adapter shares and a test can call
  without a transport, and so adapters never hold the persistence objects
  that make a stray write one method call away.
- **Enforced by:** Phan (planned): no repository references in adapter code.

## Messages

### DDD-M1: One command, one intent

A command handler, a self-handling command, and a synchronous domain event
handler never send a command. Same-transaction work is done directly through
aggregates, repositories, or domain services; later work starts from an
integration event.

- **Why:** a command that sends a command hides a second unit of work inside
  the first, and nests transactions and correlation in ways the middleware
  does not model.
- **Enforced by:** Phan (planned).

### DDD-M2: Queries only read

Query handlers and self-handling queries never send commands, record domain
events, or call repository write methods.

- **Why:** the query bus deliberately has no transaction, audit, or event
  publication. A write through it is invisible to all of them.
- **Enforced by:** Phan (planned) for commands and events; Review for
  repository writes, until repositories separate read and write methods.

### DDD-M3: Integration listeners translate, they do not decide

An `IntegrationListener` defines `get_event_class()` and `get_command()`, and
`get_command()` builds a command from the event's fields or returns `null`.
The listener has no other dependencies.

- **Why:** a listener runs outside any command's unit of work. A decision it
  makes from repository state is made without a transaction and without a
  trace step of its own. Put the decision in the command's handler, where the
  same data is read inside the unit of work.
- **Enforced by:** Phan (planned): no constructor dependencies.

### DDD-M4: A command's result is never domain state

`ICommandHandler::handle()` returns `void`. A self-handling command returns
`void`, or a scalar or DTO verdict used only to steer its transport. It never
returns a domain object, and no later domain step depends on its result.

- **Why:** a command is an intent, not a query. A result that domain code
  depends on couples two units of work that should be independent.
- **Enforced by:** the interface for handlers; Phan (planned) for
  self-handling commands.

### DDD-M5: Atomic writes opt in to a transaction

A command whose aggregate writes and integration events must commit together
implements `ITransactionalCommand` when the stock wpdb middleware is used.

- **Why:** being on the command bus does not open a transaction. Without the
  marker, the aggregate can commit while its outbox record does not, or the
  other way round.
- **Enforced by:** Review. A consumer using custom transaction middleware
  tests its own opt-in rule.

### DDD-M6: Long processes coordinate, they do not publish

A `LongProcess` step returns commands, awaits, schedules, or checkpoints. It
never starts another process or publishes an integration event directly.

- **Why:** a process is a lifecycle that waits and resumes. Events it
  published directly would bypass the commands whose domain work is meant to
  announce them.
- **Enforced by:** Phan (planned): no references to `IIntegrationEventBus` or
  `ProcessRunner` in a `LongProcess`.

## Events

### DDD-E1: Aggregates record, repositories collect

Aggregates record domain events with `event()`. A repository method that
persists an aggregate hands its events to `EventsUnitOfWork::collect_from()`,
and a repository that persists aggregates receives `EventsUnitOfWork` in its
constructor.

- **Why:** an event that is recorded but never collected is silently lost.
- **Enforced by:** Phan: `DDDAggregateEventCollectionPlugin`.

### DDD-E2: The events unit of work is never cached

`EventsUnitOfWork` is injected or resolved from the live container. It is
never stored in a static property or a static facade.

- **Why:** the middleware resets, seals, and drains one instance per command.
  A cached copy records into an instance nobody drains.
- **Enforced by:** Phan (planned).

### DDD-E3: A sealed unit of work admits only integration announcements

While synchronous handlers drain, only events implementing
`IAnnouncesIntegration` may be newly recorded.

- **Why:** prevents an unbounded cascade of plain domain events inside one
  command.
- **Enforced by:** Runtime.

### DDD-E4: An integration event's constructor is its wire schema

Constructor parameter names are payload keys, and values round-trip through
`integration_payload()` and `from_payload()`. Action names, parameter names,
and payload types are cross-plugin API: change them deliberately, in both
directions, with publisher and subscriber tests.

- **Why:** another plugin may be decoding the payload with an older or newer
  copy of the class.
- **Enforced by:** Conformance: `IntegrationConformance`.

### DDD-E5: Touches belong on the published record

`#[Touches]` goes on the integration record that is actually published, using
class references. An aggregate with recorded touches that is renamed overrides
`canonical_name()` to keep its historical name.

- **Why:** the router publishes the scalar twin and does not copy attributes
  from the source event, so touches on the wrong class never reach the
  Biography.
- **Enforced by:** Conformance: `IntegrationConformance`.

## Judgment

These rules cannot be checked mechanically. Reviews apply them.

### DDD-J1: The construct matches the lifecycle

Choose between aggregate method, domain service, command, query, domain event,
integration event, behaviour routine, and long process by the table in the
[agent skill](../.claude/skills/tangible-ddd/SKILL.md#choose-the-construct).

- **Enforced by:** Review.

### DDD-J2: No abstraction only to rename

A new construct must change ownership, lifecycle, persistence, or execution
semantics. One that only renames a handler is removed.

- **Enforced by:** Review.

### DDD-J3: Unclear boundaries are settled before code

When authority, invariants, transaction scope, time boundaries, retry,
orchestration, or ownership is unclear, the model is agreed through the
[consumer design interview](consumer-design-interview.md) before
implementation starts.

- **Enforced by:** Review.

## Consumer overlays

A consumer may add rules for its own conventions (naming, a persistence
stack, its API format) in a thin local document. It does not copy this one.

- Local rules use the consumer's own ID prefix, for example `LMS-A1`, so a
  report shows which document a rule comes from.
- The overlay names the consumer's adapter namespaces for DDD-L4 and DDD-L5.
- An overlay may make a rule here stricter. It never relaxes one; a consumer
  that cannot follow a rule records the violations in its Phan baseline and
  says why.
