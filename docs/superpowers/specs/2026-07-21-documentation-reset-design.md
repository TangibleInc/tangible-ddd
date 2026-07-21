# Tangible DDD Documentation Reset Design

**Status:** Approved for implementation on 2026-07-21.

**Scope:** Tangible DDD 0.6.x operational documentation, the canonical agent
skill, generated consumer skill handoff, and status labels for undated
historical documents. Consumer-specific LMS and Datastream corrections are
maintained on their own repository branches.

## Problem

Tangible DDD moved from 0.2.0 to 0.6.0 in ten days. The canonical
`.claude/skills/tangible-ddd/SKILL.md` was last materially updated for 0.2.0
and received only six lines for 0.2.1. It still teaches removed classes such as
`CorrelationContext`, `CommandAuditMiddleware`, and
`AsyncWordPressActionHandler`. `docs/wiring-a-consumer.md` was patched release
by release, but its framing and several examples still describe the 0.2-era
consumer.

The repository also preserves valuable design records. Their problem is not
age; it is ambiguous status. An undated or unlabelled historical plan can be
mistaken for current API guidance by a human or an agent.

## Source Of Truth

Current guidance follows this order:

1. Installed package version and current source/tests.
2. Root `README.md` and `docs/README.md` for orientation.
3. `docs/wiring-a-consumer.md` and `docs/consumer-modules.md` for supported
   integration contracts.
4. `.claude/skills/tangible-ddd/SKILL.md` for agent decision rules and links.
5. The release and migration ledger for version-specific changes.
6. Dated specs, plans, audits, and prototypes as historical evidence only.

Consumer repositories may add a thin overlay for local Doctrine, naming, or
repository conventions. They must not copy the framework manual.

## Information Architecture

### Current operational surfaces

- `README.md`: what the framework is, supported runtime, capabilities, quick
  start, execution model, storage, dashboard, and links.
- `docs/README.md`: explicit current/historical index and status vocabulary.
- `docs/wiring-a-consumer.md`: the complete top-level consumer contract for
  the current 0.6.x release.
- `docs/consumer-modules.md`: the 0.6.2 host/module contract, load order,
  separate container bridge, process catalog overlay, tests, and deactivation
  constraints.
- `docs/migration-0.2-to-0.3.md`: retained filename for inbound links, retitled
  and maintained as the release/migration ledger through 0.6.2.
- `.claude/skills/tangible-ddd/SKILL.md`: concise current agent guide, under
  500 lines, referring to the operational docs for detailed wiring.

### Historical surfaces

The following undated or misleadingly present-tense files receive a status
banner, not a hindsight rewrite:

- `docs/0.3-trace-context.md`
- `docs/dashboard/BUILD-OUTLINE.md`
- `docs/ddd-drill-inspector-dashboard-plan.md`
- `docs/framework-issues-from-consumer-review.md`
- `docs/integration-event-evolution.md`

Dated files under `docs/superpowers/`, dashboard iterations, and the explicitly
design-only topology note remain unchanged and are classified by
`docs/README.md`.

## Canonical Skill

The framework skill is a decision and navigation layer, not another book. It
must:

- tell the agent to verify the installed `tangible/ddd` version first;
- prefer source and tests over remembered consumer examples;
- explain commands, queries, domain events, integration events,
  `BehaviourWorkflow`, `LongProcess`, touches, correlation, and consumer
  modules at the decision level;
- preserve the invariant that state changes enter through the command bus and
  commands do not dispatch commands;
- distinguish separate-handler and opt-in self-handling messages;
- describe `Correlation` and immutable `TraceContext`, never the removed
  mutable context API;
- describe compiled process discovery and module routing without exposing the
  internal ontology as product vocabulary;
- include a compact removed-API table; and
- link to current docs rather than duplicating complete YAML blocks.

## Scaffolded Skill Handoff

`wp ddd init` adds a thin consumer-local
`.claude/skills/tangible-ddd/SKILL.md`. The generated file does not copy the
framework manual. It tells agents to locate and read the canonical skill from
the installed package, normally:

`vendor/tangible/ddd/.claude/skills/tangible-ddd/SKILL.md`

It also tells agents to read consumer-local architecture documents after the
framework guide. Existing files retain the scaffolder's current skip behavior;
`--force` remains the explicit overwrite mechanism.

This makes a new consumer discoverable without creating another frozen manual.

## Consumer Modules Vocabulary

The supported public term is **consumer module**. A sidecar is one packaging
form of a consumer module.

A module:

- owns a strict namespace subtree beneath one host consumer;
- uses its own compiled container;
- shares the host's exact config, prefix, persistence identity, middleware,
  unit of work, outbox, and process runner through get-only runtime factories;
- routes its command/query classes through its own terminal handlers;
- does not become a top-level dashboard consumer; and
- registers compiled process entries onto the host runner after conflict
  validation.

Operational documentation must state the exact WordPress lifecycle implemented
by 0.6.2 and must warn that active process rows contain module class names.
Removing a module therefore requires draining, migrating, or retaining
compatibility classes for those rows.

## Language

Public docs use conventional terms: command execution, domain event,
integration event, correlation, and long-running process. The internal
act/fact/trajectory ontology may appear only when explaining the corresponding
`Kind` API or a causal-storage field. It is not a headline vocabulary.

`Biography` may describe the touches-backed dashboard view, but the framework
API remains `Touches`, `Footprint`, and the touches table.

## Verification

Add `DocumentationCurrentnessTest` covering only operational surfaces. It must:

- reject removed API names and obsolete `tangible/ddd:^0.2` constraints;
- verify required operational documents exist;
- verify local Markdown links in those documents resolve; and
- verify the scaffold emits a thin skill that points to the canonical vendored
  skill without embedding removed APIs.

Historical files are intentionally excluded from the forbidden-symbol scan.
Their status banners are asserted separately.

The final branch also runs the full framework suite, PHP lint for changed PHP,
skill frontmatter validation, a repository-wide operational-symbol scan, and a
fresh agent pressure test against the rewritten skill.

## Non-Goals

- Rewriting dated plans or prototypes into fake present-tense history.
- Updating consumer runtime code or Composer constraints.
- Adding dashboard features.
- Publishing, tagging, or deploying 0.6.2.
- Making the scaffold overwrite a user's existing local skill without
  `--force`.
