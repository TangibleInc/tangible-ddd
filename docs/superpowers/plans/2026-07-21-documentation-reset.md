# Tangible DDD Documentation Reset Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development or superpowers:executing-plans to
> implement this plan task by task. Steps use checkbox syntax for tracking.

**Goal:** Replace stale 0.2-era operational guidance with one tested Tangible
DDD 0.6.2 documentation set and make new consumers discover the canonical
framework skill without copying it.

**Architecture:** Current guidance lives in six explicit operational surfaces.
Historical records retain their contents and gain status labels. A PHPUnit
currentness test guards actionable examples and local links, while the
scaffolder emits a thin skill that delegates to the installed package's
canonical skill.

**Tech Stack:** Markdown, PHP 8.1+, PHPUnit 11, WordPress WP-CLI scaffolder.

## Global Constraints

- Public language uses command, domain event, integration event, correlation,
  and long-running process; act/fact/trajectory is not headline vocabulary.
- `Consumer module` is the public capability; `sidecar` describes packaging.
- Current docs describe implemented 0.6.2 behavior only.
- Historical documents are labelled, not rewritten.
- The canonical skill stays below 500 lines and does not duplicate full YAML.
- Generated consumer skills point to the installed canonical skill and retain
  existing skip/`--force` semantics.
- Do not modify consumer runtime code, Composer constraints, lockfiles,
  generated production containers, dashboard behavior, or the user's
  uncommitted story-intersection note.

---

### Task 1: Pin operational documentation currentness

**Files:**
- Create: `tests/Unit/Documentation/DocumentationCurrentnessTest.php`

**Interfaces:**
- Consumes: repository root and Markdown files.
- Produces: a focused PHPUnit gate for required files, actionable removed API
  forms, historical banners, and local links.

- [ ] **Step 1: Write the failing currentness test**

Create a PHPUnit test with these exact operational paths:

```php
private const OPERATIONAL = [
  'README.md',
  'docs/README.md',
  'docs/wiring-a-consumer.md',
  'docs/consumer-modules.md',
  'docs/migration-0.2-to-0.3.md',
  '.claude/skills/tangible-ddd/SKILL.md',
];
```

It must reject these actionable patterns in every operational file:

```php
private const REMOVED_PATTERNS = [
  'CorrelationContext::',
  'extends AsyncWordPressActionHandler',
  'extends AsyncWordpressActionHandler',
  'new CommandAuditMiddleware',
  "'@TangibleDDD\\Application\\Logging\\CommandAuditMiddleware'",
  'TransportEnvelope::',
  'composer require tangible/ddd:^0.2',
];
```

It must require a `Status:` banner in the five historical files named by the
design spec. Its link test must parse Markdown links, ignore absolute URLs and
fragment-only links, strip fragments from local targets, resolve relative to
the containing file, and assert the target exists.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php -d auto_prepend_file=/tmp/tangible-ddd-docs-preload.php \
  vendor/bin/phpunit tests/Unit/Documentation/DocumentationCurrentnessTest.php --testdox
```

Expected: FAIL because `README.md`, `docs/README.md`, and
`docs/consumer-modules.md` do not exist; current skill examples also contain
removed actionable APIs.

- [ ] **Step 3: Commit the failing test**

```bash
git add tests/Unit/Documentation/DocumentationCurrentnessTest.php
git commit -m "test: pin current tangible ddd documentation"
```

### Task 2: Establish the current and historical documentation map

**Files:**
- Create: `README.md`
- Create: `docs/README.md`
- Modify: `docs/0.3-trace-context.md`
- Modify: `docs/dashboard/BUILD-OUTLINE.md`
- Modify: `docs/ddd-drill-inspector-dashboard-plan.md`
- Modify: `docs/framework-issues-from-consumer-review.md`
- Modify: `docs/integration-event-evolution.md`

**Interfaces:**
- Consumes: the 0.6.2 source tree and dashboard implementation.
- Produces: entry points and unambiguous document status.

- [ ] **Step 1: Write the root README**

Include: requirements; newest-copy loader; consumer-scoped storage; command and
query buses; synchronous domain events; outbox-backed integration events;
behaviour workflows; compiled long processes; causal tracing; touches-backed
biography; consumer modules; dddash; `wp ddd init`; and links to the current
docs index, wiring guide, module guide, migration ledger, and canonical skill.
Keep quick-start code to the Composer command and `wp ddd init`; detailed YAML
belongs in the wiring guide.

- [ ] **Step 2: Write the docs index**

Define three labels: `CURRENT`, `HISTORICAL`, and `DESIGN ONLY`. List every
top-level Markdown document and classify the dated `docs/superpowers/` and
dashboard iteration directories as historical collections.

- [ ] **Step 3: Add status banners without rewriting history**

Use these meanings:

- TraceContext spec: implemented beginning in 0.3; appendices may remain
  shelved; source/tests win.
- Dashboard build outline: historical v1 build plan; v1 now lives in
  `ddd-wordpress/Admin/Dashboard`; do not use its old middleware names as API.
- DDD Drill plan: early dashboard direction, superseded operationally.
- Consumer review: historical audit whose findings drove later releases.
- Integration evolution: historical 0.1-to-0.2 handoff, not current API.

- [ ] **Step 4: Run the documentation test**

Expected: it still fails only on the not-yet-written module/wiring/skill
surfaces or their removed actionable examples; historical banner assertions and
new README links pass.

- [ ] **Step 5: Commit**

```bash
git add README.md docs/README.md docs/0.3-trace-context.md \
  docs/dashboard/BUILD-OUTLINE.md docs/ddd-drill-inspector-dashboard-plan.md \
  docs/framework-issues-from-consumer-review.md docs/integration-event-evolution.md
git commit -m "docs: separate current guidance from history"
```

### Task 3: Write the 0.6.2 operating contract

**Files:**
- Rewrite: `docs/wiring-a-consumer.md`
- Create: `docs/consumer-modules.md`
- Modify: `docs/migration-0.2-to-0.3.md`

**Interfaces:**
- Consumes: `boot()`, `boot_module()`, `ConsumerRegistry`,
  `DDDCompilerPasses`, `LongProcessCatalog`, and loader priority contracts.
- Produces: supported host and module integration guidance.

- [ ] **Step 1: Rewrite top-level consumer wiring**

Document exact 0.6.2 order: Composer copy registration at
`plugins_loaded:0`, winner initialization at `1`, consumer bootstrap at `10`,
container compilation at `init:1`, and host hook registration at `init:2`.
Cover the seven consumer tables, current command/query middleware shapes,
integration listeners, outbox, migrations, compiler passes, private process
definitions, dumped-container parity, tests, and deployment checks. Link module
packaging to `consumer-modules.md`; never present it as a second `boot()`.

- [ ] **Step 2: Write the consumer module guide**

Document:

- strict descendant namespace and one host prefix;
- separate dumped module container;
- `config_for()` and get-only `service_for()` factories;
- common public host service IDs and the host-specific transaction ID;
- `boot_module()` at priority 30 and module runtime wiring at `init:3`;
- top-level-only `all()` and module `modules_for()` discovery;
- module command/query/listener routing;
- compiled `LongProcessCatalog` overlay and conflict behavior;
- no host container mutation;
- host stability requirement;
- active process row/deactivation warning; and
- opaque/dumped-container contract tests.

- [ ] **Step 3: Reframe and extend the migration ledger**

Retitle it as the release and migration ledger while retaining its filename for
inbound links. Preserve historical entries. Add exact 0.6.1 compiler catalog
and 0.6.2 consumer module sections, including loader identities and rollout
constraints.

- [ ] **Step 4: Run the focused documentation test**

Expected: required-file and local-link assertions pass. Any remaining failure
is confined to the old canonical skill.

- [ ] **Step 5: Commit**

```bash
git add docs/wiring-a-consumer.md docs/consumer-modules.md \
  docs/migration-0.2-to-0.3.md
git commit -m "docs: define the tangible ddd 0.6 operating contract"
```

### Task 4: Replace and distribute the canonical skill

**Files:**
- Rewrite: `.claude/skills/tangible-ddd/SKILL.md`
- Modify: `ddd-wordpress/cli/class-ddd-command.php`
- Modify: `tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php`

**Interfaces:**
- Consumes: current operational docs and scaffolder template map.
- Produces: canonical decision guide and a thin consumer-local handoff skill.

- [ ] **Step 1: Add the failing scaffold test**

Extend `ScaffoldTemplatesConformanceTest` to assert the template map contains
`.claude/skills/tangible-ddd/SKILL.md`; the generated content contains
`vendor/tangible/ddd/.claude/skills/tangible-ddd/SKILL.md`; and it contains none
of the actionable removed patterns from Task 1. Assert the scaffolder creates
the `.claude/skills/tangible-ddd` directory.

Run the focused scaffold test. Expected: FAIL because the skill template and
directory are absent.

- [ ] **Step 2: Rewrite the canonical skill**

Keep it below 500 lines. Include version/source verification; decision rules;
hard invariants; current command/query options; event taxonomy; routines versus
long processes; correlation; touches/biography; consumer ownership/modules;
removed API names as non-actionable migration notes; links; and a final
checklist. Do not copy full service YAML.

- [ ] **Step 3: Generate the thin skill from `wp ddd init`**

Add the directory to the scaffolder's directory list and the skill to the
template map. The generated skill must tell the agent to:

1. verify the consumer's installed `tangible/ddd` version;
2. read the canonical vendored skill;
3. inspect current source/tests if guidance conflicts; and
4. then read consumer-local architecture docs.

Do not embed framework patterns in the generated file.

- [ ] **Step 4: Run focused tests and skill validation**

Run:

```bash
php -d auto_prepend_file=/tmp/tangible-ddd-docs-preload.php \
  vendor/bin/phpunit tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php \
  tests/Unit/Documentation/DocumentationCurrentnessTest.php --testdox
```

Expected: PASS.

Validate the skill frontmatter with the available skill validator or, if it
cannot target repository-local skills, parse the YAML frontmatter and verify
`name` plus `description` manually.

- [ ] **Step 5: Commit**

```bash
git add .claude/skills/tangible-ddd/SKILL.md \
  ddd-wordpress/cli/class-ddd-command.php \
  tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php
git commit -m "docs: make the current ddd skill canonical"
```

### Task 5: Verify the complete documentation reset

**Files:** all files changed by Tasks 1-4.

**Interfaces:** Produces release-ready evidence, not new behavior.

- [ ] **Step 1: Run PHP lint for every changed PHP file**

Expected: no syntax errors.

- [ ] **Step 2: Run the full framework suite**

```bash
php -d auto_prepend_file=/tmp/tangible-ddd-docs-preload.php \
  vendor/bin/phpunit
```

Expected: zero failures; the nine existing PHPUnit deprecations may remain.

- [ ] **Step 3: Run repository checks**

Run `git diff --check`, the operational removed-pattern scan, required local
link checks, and `wc -l .claude/skills/tangible-ddd/SKILL.md`. Expected: clean
diff, no actionable removed patterns, all links present, skill under 500 lines.

- [ ] **Step 4: Forward-test the skill**

Give a fresh agent only the installed canonical skill plus a request to explain
how to access current correlation, wire a dumped-container LongProcess, and add
a host-native sidecar command. Expected: it uses `Correlation`/`TraceContext`,
compiled `LongProcessCatalog`, and consumer modules; it must not prescribe any
removed API.

- [ ] **Step 5: Request whole-branch review and fix all Important findings**

Review the complete base-to-head diff for present-tense accuracy, source/API
agreement, copy/paste safety, link integrity, and accidental historical edits.
