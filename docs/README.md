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

## Current

| Document | Purpose |
| --- | --- |
| [Package README](../README.md) | Requirements, runtime model, capabilities, storage, and quick start |
| [This documentation map](README.md) | Status vocabulary and navigation |
| [Wiring a consumer](wiring-a-consumer.md) | Complete top-level consumer, DI, middleware, table, and deployment contract |
| [Consumer modules](consumer-modules.md) | Host/module lifecycle, separate-container bridge, routing, and process overlay |
| [Release and migration ledger](migration-0.2-to-0.3.md) | Version-by-version consumer changes; the old filename is retained for inbound links |
| [Canonical agent skill](../.claude/skills/tangible-ddd/SKILL.md) | Current modeling decisions and source navigation for coding agents |

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
| [Implementation plans and specs](superpowers/) | Dated implementation evidence, not operational guidance |

## Design only

| Document | Scope |
| --- | --- |
| [Outbox transport payload strategies](outbox-transport-payload-strategies.md) | Possible transport optimization; explicitly not a current change |
| [Multi-item behaviour topology](topology/multi-item-behaviour-topology.md) | Topology exploration rather than a shipped contract |

The directories under `superpowers/` and `dashboard/iterations/` are retained
as historical collections even when an individual plan describes behavior that
later shipped. Their dates and decision context remain useful; their examples
do not outrank current code.
