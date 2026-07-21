# DDD Drill Inspector Dashboard Plan

> **Status: HISTORICAL DIRECTION.** This early dashboard proposal was
> superseded operationally by the dashboard under
> `ddd-wordpress/Admin/Dashboard`. It remains useful as design provenance, not
> as a current API or build checklist.

Date: 2026-06-24

This document captures the dashboard direction that emerged from reviewing
`tangible-ddd` consumers such as `tangible-datastream` and `tangible-cred`.
The dashboard is not a datastream dashboard. It is a generic DDD runtime
inspector with optional plugin-specific lenses.

## Core thesis

Each DDD-consuming plugin owns its own DDD table set. The dashboard must respect
that storage boundary.

However, `correlation_id` can be propagated across plugin contexts through
integration events, outbox jobs, and process resumes. That makes it a useful
cross-context trace token, not a shared table key.

Short version:

> Each plugin owns its tables. Correlation is the cross-context join token.

## What not to build

Do not build a global dashboard that uses SQL `UNION` across every plugin's DDD
tables for ordinary list views.

That would be noisy, slow, and semantically weak because each plugin owns its
own storage lifecycle, schema prefix, domain enrichments, and operational
meaning.

Do not make the plugin dropdown a cosmetic filter over one global dataset.
There is no one global dataset.

Do not treat plugin context as a trace boundary. A trace may cross contexts if
the correlation token is propagated.

## Context registry

The inspector should discover DDD contexts through a framework registration
point, for example:

```php
apply_filters('tangible_ddd_inspector_apps', []);
```

Each context should register:

- `key`: stable context key, for example `tangible-datastream`
- `label`: human-readable label
- `prefix`: DDD config prefix
- `config`: `IDDDConfig` or enough metadata to construct table names
- `capability`: who can inspect it
- `tables`: optional explicit table map
- `enrichers`: optional app-specific trace/list enrichers

The inspector should also be able to degrade gracefully. If a plugin-specific
enricher is unavailable, the core DDD tables should still be inspectable.

## Dashboard modes

### Normal mode

Normal tabs operate against one selected DDD context.

Examples:

- selected context: `tangible-cred`
- show only cred command audit rows
- show only cred outbox rows
- show only cred long processes
- show only cred behaviour workflows

No cross-plugin `UNION` in normal mode.

### Trace mode

Trace mode starts with an explicit token, usually `correlation_id`.

The inspector fans out:

```text
for each registered DDD context:
  query command_audit by correlation_id
  query integration_outbox by correlation_id
  query integration_dlq by correlation_id
  query long_processes by correlation_id
  query behaviour workflow tables where traceable
  ask optional app lens for domain enrichments
```

Then it merges normalized trace fragments in memory.

This keeps storage local while still making cross-plugin traces visible.

## Trace fragment contract

Each context should expose trace data as normalized fragments:

```php
[
  'context' => 'tangible-datastream',
  'kind' => 'command|outbox|dlq|process|workflow|app',
  'occurred_at' => '2026-06-24T10:00:00Z',
  'correlation_id' => '...',
  'local_id' => '...',
  'related_ids' => [
    'command_id' => '...',
    'event_id' => '...',
    'process_id' => 123,
  ],
  'summary' => '...',
  'payload_preview' => [],
  'severity' => 'info|warning|error',
]
```

The UI can sort and group fragments without requiring every plugin to share one
physical schema.

## Suggested tabs

### Overview

Context-scoped rollup.

Shows:

- command failures
- pending outbox backlog
- retrying/failed outbox rows
- DLQ count
- stale locks
- suspended long processes
- behaviour workflow backlog
- schema/version drift

Rows should link into the relevant context-scoped tab.

### Trace

The primary diagnostic tab.

Inputs:

- `correlation_id`
- `command_id`
- outbox `event_id`
- process id
- workflow id/work item id
- optional plugin-specific ids from enrichers

For `correlation_id`, fan out across contexts. For local ids, start in the
selected context unless the user chooses all contexts.

Output:

- combined timeline
- grouped by context and lifecycle phase
- visible transitions from command to events to outbox to integration callback
  to process/workflow

### Commands

Context-scoped command audit browser.

Shows:

- command id
- correlation id
- command class
- source
- status
- duration
- redacted params
- published events
- error

Row jumps:

- correlation id -> Trace
- command id -> command detail
- published event -> Outbox or Wiring

### Outbox and DLQ

Context-scoped outbox operational view.

Shows:

- event id
- event type
- integration action
- correlation id
- command id
- status
- attempts
- max attempts
- next attempt
- lock owner
- payload bytes
- last/final error

Row jumps:

- correlation id -> Trace
- command id -> Commands
- DLQ row -> DLQ detail/replay action

### Long Processes

Developer-authored lifecycle inspector.

LongProcess is for named business stories that should stay in one place:
multi-step, suspend/resume, awaited event, external callback, resource limit,
compensation.

Shows:

- process class
- business data
- status
- current step
- waiting event
- match criteria
- correlation id
- payload
- compensation state

Row jumps:

- correlation id -> Trace
- waiting event -> Wiring
- process class -> registered process metadata

### Behaviour Workflows

Configurable behaviour inspector.

BehaviourWorkflow is for configurable/admin-authored sequences and work-item
ledgers: retry, notify, wait, stop, fork failed work, batch execution.

Shows:

- workflow definition
- current phase
- behaviour type
- active work items
- failed work items
- retry/fork state
- associated command/outbox/process ids when available

### Wiring

Runtime map of DDD wiring.

Shows:

- command -> handler mappings
- query -> handler mappings
- domain event handlers
- integration event actions
- outbox publisher
- process awaited events
- behaviour types
- DI tags

This tab should make framework reflection useful without putting reflection in
the core execution path.

### Storage

Context-scoped storage diagnostics.

Shows:

- expected tables
- table existence
- schema version
- row counts
- important indexes
- stale lock counts
- options/config state

### App Lens

Optional plugin-specific domain panels.

Examples:

- datastream: event sources, subscriptions, destinations, delivery log
- cred: endpoints, endpoint attempts, accreditations, earnings
- lms: courses, enrollments, learning-event processes

The generic inspector must not depend on these panels.

## Correlation semantics

`correlation_id` is generated randomly for a new command context. It is stored
in that plugin's DDD tables, then forgotten from static PHP memory when the
command middleware resets.

It can cross plugin contexts only if propagated:

```text
Plugin A command creates correlation X
Plugin A writes outbox row with X
Outbox/integration callback restores X
Callback dispatches Plugin B command
Plugin B command audit/outbox rows store X in Plugin B tables
```

Therefore:

- unrelated plugin commands will not share a correlation id
- integration command chains can span plugins
- trace mode should search all contexts for the same token
- normal dashboard mode should remain context-scoped

## Command dispatch semantics

Framework law:

- Command handlers must not dispatch commands.
- Boundary adapters may dispatch commands.
- Integration callbacks are boundary adapters.
- Two successive commands from a boundary adapter are allowed, but they carry a
  smell.

Boundary adapters include:

- REST controllers
- WP hooks
- CLI commands
- cron callbacks
- admin actions
- integration event callbacks
- tests

Integration event callbacks should do only light parameter work:

- restore context
- deserialize payload
- cast/normalize primitive args
- dispatch command(s)

If a callback starts doing real orchestration, extract a named command,
`LongProcess`, or `BehaviourWorkflow`.

## Smell rules for the inspector

Errors:

- nested command dispatch detected
- outbox row missing `correlation_id`
- DLQ row missing final error/final attempt data
- suspended LongProcess waiting for an event nobody registers
- schema/table mismatch

Warnings:

- boundary callback emits multiple command audit rows
- outbox row missing `command_id` outside known process/system paths
- high pending outbox backlog
- stale locks
- BehaviourWorkflow work items accumulating without progress
- process suspended beyond threshold

Signals:

- same `correlation_id` appears in multiple plugin contexts
- command chain crossed a plugin boundary through integration propagation
- long process resumed from awaited event
- behaviour workflow forked failed items into a child workflow

## Row identity

Never identify a row only by numeric `id`.

Use:

```text
context + table/kind + local_id
```

Examples:

- `tangible-cred:command_audit:42`
- `tangible-datastream:integration_outbox:381`
- `tangible-lms:long_processes:17`

This avoids false equivalence across plugin-owned table sets.

## First useful build

1. Implement context registry.
2. Implement context-scoped Overview, Commands, Outbox/DLQ, Long Processes.
3. Implement Trace fan-out by `correlation_id`.
4. Add normalized trace fragments.
5. Add basic smell rules.
6. Add app lens registration hook.
7. Add datastream and cred lenses only after the generic inspector works.

The first version can be read-only. Replay, cancel, retry, quarantine, and
process intervention actions should come later, after the inspector proves it
can explain what happened.
