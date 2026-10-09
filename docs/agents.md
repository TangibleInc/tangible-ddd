# Tangible DDD for coding agents

**Status:** CURRENT for the 0.6.x line.

This is the entry point for a coding agent working in a project built on
Tangible DDD. It is short on purpose: it tells you what to read, the rules you
are held to, and how your work is checked. The documents it links hold the
detail.

A project points its own `AGENTS.md` (or `CLAUDE.md`) at this file inside its
installed package, so the guidance always matches the framework version the
project has locked. See [Pointing a project at this file](#pointing-a-project-at-this-file).

## Before you change anything

1. **Find the installed version.** Run `composer show tangible/ddd` in the
   project, and read the copy under `vendor/tangible/ddd/`, not a copy from
   another project or from memory. Its source and tests outrank every
   document, this one included.
2. **Read the project's overlay.** The project's `AGENTS.md` names its layer
   namespaces, its own rules (with their own ID prefix), and the commands it
   runs. An overlay can make a rule stricter. It never relaxes one.
3. **Pick the reading for the task:**

| You are | Read |
| --- | --- |
| Changing domain or application code | [Modeling rules](modeling-rules.md), then the [agent skill](../.claude/skills/tangible-ddd/SKILL.md) |
| Designing something whose boundaries are unclear | [Consumer design interview](consumer-design-interview.md), before any code |
| Wiring a container, a consumer, or its tables | [Wiring a consumer](wiring-a-consumer.md) |
| Adding a module to another consumer | [Consumer modules](consumer-modules.md) |
| Upgrading the framework | The [release ledger](migration-0.2-to-0.3.md) entry for every version in between |
| Following an old plan or spec | The [documentation map](README.md) first: historical documents are not guides |

## The rules, in one line each

The full text, with the reason for each rule and how it is enforced, is in
[modeling rules](modeling-rules.md). Cite the ID in reviews and commit
messages.

**Layers**

- **DDD-L1** The domain depends only on itself, `TangibleDDD\Domain`, PHP, and
  the pure libraries the project lists. No WordPress, no persistence.
- **DDD-L2** Only domain services (classes implementing `IDomainService`) use
  repositories. Aggregates, entities, and value objects never do.
- **DDD-L3** Repository interfaces live in the domain and speak domain types.
- **DDD-L4** The application layer never references adapters. Queries return
  application DTOs, not response classes from the API.
- **DDD-L5** Adapters (REST, admin screens, CLI, hook callbacks) write only by
  sending commands and read only by running queries. They never hold a
  repository or call an aggregate.

**Messages**

- **DDD-M1** A command handler or synchronous event handler never sends a
  command. Do same-transaction work directly; start later work from an
  integration event.
- **DDD-M2** Queries only read.
- **DDD-M3** An integration listener only turns the event into a command, or
  `null`. It has no dependencies and makes no decisions.
- **DDD-M4** A command returns nothing that domain code depends on.
- **DDD-M5** A command whose writes must commit together implements
  `ITransactionalCommand`. Being on the command bus does not open a
  transaction.
- **DDD-M6** A `LongProcess` returns commands, awaits, and schedules. It never
  starts another process or publishes an integration event.

**Events**

- **DDD-E1** Aggregates record events; the repository that saves them calls
  `collect_from()`.
- **DDD-E2** `EventsUnitOfWork` is injected, never cached in a static.
- **DDD-E3** After the handler returns, only integration announcements may be
  recorded.
- **DDD-E4** An integration event's constructor is its wire schema. Renaming a
  parameter is a breaking change for every subscriber.
- **DDD-E5** `#[Touches]` goes on the record that is actually published.

**Judgment**

- **DDD-J1** Choose the construct by its lifecycle, using the table in the
  [agent skill](../.claude/skills/tangible-ddd/SKILL.md#choose-the-construct).
- **DDD-J2** No new abstraction only to rename a handler.
- **DDD-J3** When authority, invariants, transaction scope, timing, retry, or
  ownership is unclear, ask before writing code.
- **DDD-J4** A chain of processes nobody designed is a modeling question, not
  an accident to keep.

## How to work

- **Tests come first.** Write the failing test that states the behavior, then
  the code. Only tests for failure paths may follow the code. Test domain
  behavior through
  aggregates and handlers, without WordPress.
- **One question at a time.** When a requirement leaves a boundary open
  (DDD-J3), ask the developer the single question that settles the most, and
  keep a short list of provisional decisions. Do not choose silently.
- **Logic lives in handlers, not at the edges.** A hook callback or admin
  screen that needs data defines a query; one that causes change sends a
  command. If an edge class grows private methods that compute business facts,
  move them into the handler, where they can be tested.
- **Reference other aggregates by ID.** Objects inside one aggregate may hold
  each other; a reference to another aggregate, a WordPress user, or a post is
  a plain ID. Each aggregate is saved in its own unit of work.
- **Never drop events to get past an error.** Calling `pull_events()` before a
  save deletes the events, and subscribers in other plugins stop hearing about
  them with no error anywhere. If a fact must survive the sealed phase
  (DDD-E3), make it an integration announcement.
- **Keep one home for each kind of class.** Enums, including those a read
  model uses, go in `Domain\Enums`. A query's filter or parameter objects sit
  beside the query. Check where the project actually puts things instead of
  repeating where you put them last time.
- **Keep services mockable.** PHPUnit cannot mock a `readonly class`. A service
  that tests replace is a plain class with `private readonly` promoted
  properties. Commands, DTOs, and value objects can stay `readonly class`.
- **Register what the container builds.** A class fetched from the container
  must be declared in the project's service configuration, by a directory
  resource or explicitly. A missing one fails when it is resolved, and if that
  happens while the plugin boots, every request fails. See [Wiring a consumer](wiring-a-consumer.md#common-wiring-failures).

## How your work is checked

- **Phan**, with the plugins from `tangible/phan-ddd-plugins`:
  `DDDAggregateEventCollectionPlugin` checks DDD-E1, and `LayerRulesPlugin`
  (from 0.2.0) checks DDD-L1, L4, and L5 against the layer map in the
  project's Phan configuration. Each issue starts with the rule ID. New code
  never adds to a Phan baseline.
- **Conformance**: `IntegrationConformance` in the project's tests checks
  DDD-E4 and E5.
- **Review**: everything else, with [modeling rules](modeling-rules.md) as the
  rubric.

Before you call the work done, go through the
[finish checklist](../.claude/skills/tangible-ddd/SKILL.md#finish-checklist).

## Pointing a project at this file

Composer installs this package with its documentation, so a project can link
here instead of copying the rules. Add to the project's `AGENTS.md`:

```markdown
## Tangible DDD

This project is built on Tangible DDD. Before changing code under `src/`,
read `vendor/tangible/ddd/docs/agents.md` and follow it. It matches the
framework version in `composer.lock`. If `vendor/` is missing, run
`composer install`, or read the same file on GitHub at the tag in
`composer.lock`: https://github.com/TangibleInc/tangible-ddd/blob/<tag>/docs/agents.md
```

Then add the project's overlay below it: its layer namespaces, the pure
libraries its domain may use, its own rules, and its test and Phan commands.

- Give the full path. `vendor/` is usually ignored by git, and some agents
  leave ignored files out of search, but every agent can read a file by path.
- Claude Code reads `CLAUDE.md`, not `AGENTS.md`. A `CLAUDE.md` containing the
  line `@AGENTS.md` loads the project's file, and an `@vendor/tangible/ddd/docs/agents.md`
  line inside `AGENTS.md` loads this one.
- In a plugin whose `composer.json` sits in a subdirectory, the path starts
  there, for example `plugins/lms/vendor/tangible/ddd/docs/agents.md`.
