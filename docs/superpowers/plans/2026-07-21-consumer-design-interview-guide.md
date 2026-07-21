# Consumer Design Interview Guide Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a consumer developer's LLM a Tangible DDD-specific interview
protocol that discovers domain and runtime boundaries before prescribing code.

**Architecture:** The canonical skill contains a short trigger and dialogue
protocol. A dedicated current document contains the adaptive question funnel,
decision ledger, adversarial review, and final handoff. The documentation test
pins that guide as operational so removed APIs and broken links fail CI.

**Tech Stack:** Markdown, Tangible DDD canonical Claude skill, PHPUnit 11
documentation conformance tests, `quick_validate.py` skill validation.

## Global Constraints

- Ask one material question at a time; do not force the whole question bank on
  already-exact changes.
- Maintain a provisional model, challenge contradictions, and obtain approval
  before implementation.
- Cover general DDD, real transaction boundaries, and Tangible DDD commands,
  events, routines, `LongProcess`, Biography, tracing, and consumer modules.
- Use public consumer vocabulary. Do not make act/fact/trajectory the primary
  language.
- Keep the canonical skill concise; the full interview lives in one linked
  guide.
- Current framework source and tests outrank the guide.

---

### Task 1: Pin the interview guide as a current documentation surface

**Files:**
- Modify: `tests/Unit/Documentation/DocumentationCurrentnessTest.php`
- Test: `tests/Unit/Documentation/DocumentationCurrentnessTest.php`

**Interfaces:**
- Consumes: the existing `OPERATIONAL` provider and local-link/removed-API
  assertions.
- Produces: CI coverage for `docs/consumer-design-interview.md`.

- [x] **Step 1: Add the missing guide to `OPERATIONAL`**

Insert it between the consumer wiring and module guides:

```php
private const OPERATIONAL = [
  'README.md',
  'docs/README.md',
  'docs/wiring-a-consumer.md',
  'docs/consumer-design-interview.md',
  'docs/consumer-modules.md',
  'docs/migration-0.2-to-0.3.md',
  '.claude/skills/tangible-ddd/SKILL.md',
];
```

- [x] **Step 2: Run the focused test and verify RED**

Run:

```bash
php -d auto_prepend_file=/tmp/tangible-ddd-docs-preload.php \
  vendor/bin/phpunit --filter DocumentationCurrentnessTest
```

Expected: failure naming `docs/consumer-design-interview.md` as absent; no
unrelated runtime failure.

- [x] **Step 3: Commit the red contract**

```bash
git add tests/Unit/Documentation/DocumentationCurrentnessTest.php
git commit -m "test: require the consumer ddd interview guide"
```

### Task 2: Add the interview guide and canonical skill trigger

**Files:**
- Create: `docs/consumer-design-interview.md`
- Modify: `.claude/skills/tangible-ddd/SKILL.md`
- Modify: `README.md`
- Modify: `docs/README.md`
- Test: `tests/Unit/Documentation/DocumentationCurrentnessTest.php`

**Interfaces:**
- Consumes: current 0.6.2 contracts from `docs/wiring-a-consumer.md`,
  `docs/consumer-modules.md`, and the canonical skill.
- Produces: a linked `docs/consumer-design-interview.md` current guide and the
  skill behavior that invokes it.

- [x] **Step 1: Create the guide with an explicit current-status banner**

The document begins:

```markdown
# Designing a consumer with Tangible DDD

> **Status: CURRENT FOR 0.6.2.** This is a dialogue guide for modeling a
> consumer change before implementation. Verify the installed framework and
> consumer wiring before turning the resulting model into code.
```

It then implements these exact sections:

```text
How the LLM should conduct the dialogue
Start with the business
Locate authority and invariants
Draw the real transaction boundary
Choose the command and synchronous reactions
Cross the time or plugin boundary
Choose no orchestrator, a routine, or a LongProcess
Design retry and idempotency
Assign consumer and module ownership
Plan Biography and trace visibility
Prove the model and rollout
Provisional decision ledger
Adversarial review
Design handoff
```

Each section contains adaptive questions plus a short `Why this changes the
design` explanation. Required question content:

- actor, intent, success, forbidden outcome, and domain language;
- aggregate/authority, invariant, concurrency, and real domain-service
  knowledge;
- atomic writes, actual connection, crash points, and external effects;
- one command intent, direct same-transaction work, and synchronous domain
  events that do not dispatch commands;
- scalar/reversible integration facts, wire keys, listener translation, and
  later commands;
- configurable work-item routine versus named durable business lifecycle;
- duplicate delivery, idempotency key, retry owner, terminal failure, and
  operator action;
- top-level consumer versus integration contract versus consumer module;
- touches/Biography, correlation/causation, redaction, retention, and missing
  consequence visibility; and
- invariant, rollback, codec, retry, dump, module, migration, and dashboard
  tests.

The guide includes this decision ledger:

```text
Outcome:
Actor and intent:
Authority / aggregate:
Invariant:
Command boundary:
Atomic writes and actual connection:
Synchronous reactions:
Published integration facts:
Later commands / external effects:
Routine or long-process lifecycle:
Retry and idempotency key:
Owning consumer / module:
Biography and trace expectations:
Open contradictions:
```

The handoff headings are exactly:

```text
Problem and language
Invariant and authority
Command and transaction boundary
Synchronous domain work
Integration facts and later commands
Routine/process decision
Failure, retry, and idempotency
Consumer/container ownership
Biography, trace, and operational visibility
Tests, migration, and unresolved risks
```

- [x] **Step 2: Add the design-dialogue trigger to the canonical skill**

Insert after `Hard invariants` and before `Choose the construct`:

```markdown
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
```

Add the same link to the skill's initial installed-contract reading list.

- [x] **Step 3: Link the guide from both documentation indexes**

Add this root README list item:

```markdown
- [Consumer design interview](docs/consumer-design-interview.md)
```

Add this row to `docs/README.md` under **Current**:

```markdown
| [Consumer design interview](consumer-design-interview.md) | Adaptive questions for discovering invariants, transaction boundaries, orchestration, ownership, and proof before coding |
```

- [x] **Step 4: Run focused currentness/link tests and verify GREEN**

Run:

```bash
php -d auto_prepend_file=/tmp/tangible-ddd-docs-preload.php \
  vendor/bin/phpunit --filter DocumentationCurrentnessTest
```

Expected: all `DocumentationCurrentnessTest` cases pass with only the existing
PHPUnit deprecation count.

- [x] **Step 5: Validate the skill**

Run:

```bash
python3 /Users/titustc/.codex/skills/.system/skill-creator/scripts/quick_validate.py \
  .claude/skills/tangible-ddd
wc -l .claude/skills/tangible-ddd/SKILL.md
```

Expected: validator success and fewer than 500 skill lines.

- [x] **Step 6: Commit the guide and wiring**

```bash
git add docs/consumer-design-interview.md .claude/skills/tangible-ddd/SKILL.md \
  README.md docs/README.md
git commit -m "docs: teach agents to interview ddd consumers"
```

### Task 3: Pressure-test and verify the complete documentation branch

**Files:**
- Verify: `docs/consumer-design-interview.md`
- Verify: `.claude/skills/tangible-ddd/SKILL.md`
- Verify: all current operational docs and framework tests

**Interfaces:**
- Consumes: the complete current docs and skill.
- Produces: evidence that an unfamiliar agent asks before prescribing and that
  the branch remains executable.

- [x] **Step 1: Run a fresh-agent pressure scenario**

Give a fresh agent only the installed canonical skill and current linked docs,
then ask:

```text
When a learner finishes a course, update enrollment, issue a certificate,
email it, notify a CRM plugin, retry failures, and let admins configure extra
actions. Design and implement this in Tangible DDD.
```

Expected first-turn behavior: the agent does not immediately prescribe
classes. It asks one material question about the invariant, atomic state, or
authority, and explains the consequence. After supplied answers, it separates
the enrollment command transaction, later integration consequences,
configurable routine, possible durable lifecycle, idempotency, and plugin
ownership using current APIs only.

- [x] **Step 2: Run final documentation and framework verification**

Run:

```bash
php -d auto_prepend_file=/tmp/tangible-ddd-docs-preload.php vendor/bin/phpunit
git diff --check 49cca0f..HEAD
rg -n 'CorrelationContext::|extends AsyncWordPressActionHandler|extends AsyncWordpressActionHandler|new CommandAuditMiddleware|TransportEnvelope::|composer require tangible/ddd:\^0\.2' \
  README.md docs/README.md docs/wiring-a-consumer.md \
  docs/consumer-design-interview.md docs/consumer-modules.md \
  docs/migration-0.2-to-0.3.md .claude/skills/tangible-ddd/SKILL.md
```

Expected: PHPUnit green; diff check green; removed-surface scan prints nothing.
If the 0.6.2 base advances during review, replace `49cca0f` with the final base
commit before running the diff check.

- [x] **Step 3: Review pressure-test findings**

Fix only an Important failure: prescribing before questioning, contradicting a
hard invariant, using a removed API, or omitting transaction/plugin ownership.
Re-run Steps 1 and 2 after any correction.

- [x] **Step 4: Record final branch state**

```bash
git status --short
git log --oneline --decorate -12
```

Expected: only the intentional untracked `vendor` symlink remains; all
documentation work is committed.
