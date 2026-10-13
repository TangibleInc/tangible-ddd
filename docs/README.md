# Tangible DDD documentation

This index separates current operating contracts from the design records that
produced them.

- **CURRENT** describes supported behavior in the 0.6.x source tree.
- **HISTORICAL** preserves an audit, migration handoff, build plan, or earlier
  specification. It may contain removed API names and must not be used as a
  copy-and-paste guide.
- **DESIGN ONLY** explores a possible or deliberately unimplemented direction.

When documents disagree, use the installed package version, source, and tests
first; then the current documents below.

Three places explain *why*, and each kind of reason has one home:

- A **rule** that a check, review, or scanner can enforce goes in
  [modeling rules](modeling-rules.md), with a short reason.
- A **decision** that closed off a real alternative goes in an
  [ADR](adr/), including owner rulings and rejected names.
- A **historical** document keeps its original reasoning frozen. When one of
  its decisions is still in force, an ADR restates it and the history stays
  as the long-form record.

## Current

| Document | Purpose |
| --- | --- |
| [Package README](../README.md) | Requirements, runtime model, capabilities, storage, and quick start |
| [This documentation map](README.md) | Status vocabulary and navigation |
| [Glossary](../GLOSSARY.md) | The framework's ubiquitous language: one canonical term per concept, with the names to avoid |
| [Decision records](adr/) | One-paragraph ADRs: what was decided, why, and which alternatives are closed. Rulings recorded here are not relitigated |
| [Wiring a consumer](wiring-a-consumer.md) | Complete top-level consumer, DI, middleware, table, and deployment contract |
| [Consumer design interview](consumer-design-interview.md) | Adaptive questions for discovering invariants, transaction boundaries, orchestration, ownership, and proof before coding |
| [Modeling rules](modeling-rules.md) | The checkable rules with stable IDs, their reasons, and how each is enforced |
| [Consumer modules](consumer-modules.md) | Host/module lifecycle, separate-container bridge, routing, and process overlay |
| [Eventing lanes](eventing-lanes.md) | The three raising lanes, reschedule-via-fact, the WorkflowHandler phase lock, and the replay-not-splice rule |
| [DDDash v2 scope](dashboard/V2-OUTLINE.md) | Implemented unified trace, Aggregate Biography, consumer provenance, and explicit non-goals |
| [Release and migration ledger](migration-0.2-to-0.3.md) | Version-by-version consumer changes; the old filename is retained for inbound links |
| [Canonical agent skill](../.claude/skills/tangible-ddd/SKILL.md) | Current modeling decisions and source navigation for coding agents |
| [For coding agents](agents.md) | Short entry point a consumer's `AGENTS.md` links to inside `vendor/`: what to read, the rules in one line each, and how work is checked |

## Historical

| Document | Status |
| --- | --- |
| [TraceContext and correlation spec](0.3-trace-context.md) | Implemented beginning in 0.3 and subsequently evolved; appendices include shelved ideas |
| [Dashboard v1 build outline](dashboard/BUILD-OUTLINE.md) | Build plan for the dashboard now under `ddd-wordpress/Admin/Dashboard` |
| [DDD Drill inspector plan](ddd-drill-inspector-dashboard-plan.md) | Early dashboard direction |
| [Framework issues from consumer review](framework-issues-from-consumer-review.md) | Audit that drove later releases |
| [Integration event evolution](integration-event-evolution.md) | 0.1-to-0.2 architecture handoff |
| [Outbox pause design](outbox-pause-design.md) | Built design record; inspect current source before using its API details |
| [Dashboard iterations](dashboard/iterations/) | Dated design and reconstruction notes |

## Design only

| Document | Scope |
| --- | --- |
| [Outbox transport payload strategies](outbox-transport-payload-strategies.md) | Possible transport optimization; explicitly not a current change |
| [Multi-item behaviour topology](topology/multi-item-behaviour-topology.md) | Topology exploration rather than a shipped contract |

`dashboard/iterations/` is retained as a historical collection even where an
individual note describes behavior that later shipped. Its dates and decision
context remain useful; its examples do not outrank current code.

Implementation specs and plans are not kept in the tree. A spec lives on its
feature branch under `docs/specs/` and is deleted in the PR that ships it,
after its lasting decisions move into ADRs, the glossary or current docs; the
earlier `docs/superpowers/` collection was retired the same way. Git history
keeps every one of them.
