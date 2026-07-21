# Designing a consumer with Tangible DDD

> **Status: CURRENT FOR 0.6.2.** This is a dialogue guide for modeling a
> consumer change before implementation. Verify the installed framework and
> consumer wiring before turning the resulting model into code.

This guide is for the LLM working with a Tangible DDD consumer developer. Its
job is not to win a pattern-naming exercise. Its job is to expose the business
decision, real transaction boundary, time boundary, and operational ownership
before either person commits to classes.

Use it when a feature is underspecified, crosses persistence or plugin
boundaries, introduces retry/waiting/configurable behaviour, or could plausibly
be modeled in more than one way. Do not force it onto a narrow change whose
intent and boundaries are already exact.

## How the LLM should conduct the dialogue

Inspect what the repository can answer; ask the developer what only the domain
owner can decide.

Before asking a modeling question:

1. Verify the consumer's installed `tangible/ddd` version and its active
   container build.
2. Trace the current entry point, writes, domain events, integration events,
   database connections, and subscribers.
3. Separate observed facts from assumptions and product decisions.
4. Select the unanswered question whose answer would change the most of the
   design.

Then ask **one question at a time**. Explain why it matters when the consequence
is not obvious. Keep a provisional decision ledger privately; show the changed
or disputed part rather than printing the entire ledger after every reply.

Do not ask a developer to locate a class, service ID, table, subscriber, or
runtime version that can be inspected. Do ask who owns a business decision,
what must be atomic, what may happen later, and what failure is acceptable.

When an answer conflicts with an earlier answer, say so concretely:

```text
You said enrollment completion must roll back if CRM notification fails, but
CRM is a remote system outside the enrollment database transaction. We need to
choose: make CRM availability part of accepting completion, or commit
completion and track CRM notification as a durable later consequence.
```

Stop questioning when the material boundaries are known. Propose the model,
label assumptions and remaining risks, and obtain approval before implementing
it.

### Choose the first question

| Signal in the request | High-information first question |
| --- | --- |
| "Update A, create B, then notify C" | Which state changes must commit or roll back together, and which may happen later? |
| "When this WordPress hook fires" | What business intent does the hook represent, and can WordPress legitimately fire it more than once? |
| "Retry it" | What operation is safe to repeat, under which stable idempotency key, and where is attempt state authoritative? |
| "Admins configure the steps" | Is this a configurable routine over work items, or a fixed business lifecycle whose stages are code-owned? |
| "Wait until another plugin does X" | What durable lifecycle is waiting, which integration fact wakes it, and what identity must match? |
| "Make the sidecar native to LMS" | Does the code share LMS domain authority and operational identity, or is this genuinely another domain integrating with LMS? |
| "All of it must be transactional" | Which physical connection owns each write or effect? |

## Start with the business

Ask only the questions not already answered by the request:

- Who initiates this, and in which role?
- What single outcome are they trying to achieve?
- What observable condition means it succeeded?
- What must never become possible, even under concurrency or retry?
- Which terms have a precise meaning to the business?
- Is this a new decision, or a new transport/UI path to an existing decision?

Prefer domain language over framework language. "Who is allowed to complete an
enrollment?" is better than "Which aggregate should own this command?" Once the
answer is clear, map it to the framework construct and explain the mapping.

**Why this changes the design:** a UI action, cron tick, webhook, REST request,
and `save_post` callback may all be adapters for the same intention. Modeling
the adapter instead of the intention creates duplicate commands and lets the
business rule drift between entry points.

## Locate authority and invariants

Establish where the decision is allowed to happen:

- Which entity or lifecycle has enough state to accept or reject the change?
- What invariant does it protect?
- What identifier and version distinguish the same subject under concurrent
  work?
- Which facts must be loaded to decide, and which are merely useful context?
- Does the decision span several aggregates? If so, is there one true authority
  or must the result become eventually consistent?
- What happens when two requests make conflicting decisions simultaneously?
- Could a domain expert name the proposed domain service as a business policy,
  or is it application procedure with a domain-sounding name?

An aggregate protects invariants inside one consistency boundary. A domain
service is appropriate for genuine business knowledge that does not belong to
one aggregate, often involving repositories or several domain concepts. It is
normally invoked from a command handler and participates in that command's unit
of work. It is not a new write door.

A handler may coordinate loading, invoking the authority, and persisting. Do
not move that procedure into a domain service merely to make the handler short.

**Why this changes the design:** without explicit authority, two handlers can
both "helpfully" enforce different versions of the rule. Without a concurrency
answer, a correct single-request model can still violate its invariant in
production.

## Draw the real transaction boundary

Write down every state change and its physical storage connection. Then ask:

- Which writes must either all commit or all roll back?
- Are those writes actually on the same Doctrine/PDO/wpdb connection?
- Does the command implement `ITransactionalCommand`, or satisfy the explicit
  opt-in contract of the consumer's custom transaction middleware?
- Which consequences can occur after commit without falsifying the original
  outcome?
- What remains correct if the process dies before the handler, during a write,
  after the domain write but before commit, or immediately after commit?
- Is an email, HTTP request, payment call, file write, or another plugin's
  database being treated as if it shared the transaction?
- What is the recovery story when an external effect succeeds but its local
  acknowledgement fails?

The stock Tangible DDD transaction middleware opens a database transaction only
for `ITransactionalCommand`. Being on the command bus or appearing inside the
middleware chain is not enough. Consumer-specific Doctrine/PDO middleware may
have its own gate, which must be inspected and tested.

When the command opts in, the transaction wraps aggregate persistence,
domain-event drain, and transactional outbox publication. That atomicity is
real only when aggregate writes and the outbox use the same transaction
connection. Domain events run synchronously during command execution;
integration events are committed records whose consumers run later.

Remote APIs, email delivery, and a second independent connection are not made
atomic by calling them from the handler. Either make their availability a
precondition before starting the transaction, or represent their later work
durably and design idempotent recovery.

**Why this changes the design:** desired atomicity is not evidence of physical
atomicity. The transaction boundary determines what belongs in direct domain
work, what belongs in a synchronous domain-event handler, and what must become
a later integration consequence.

## Choose the command and synchronous reactions

Ask:

- What one intention should appear in command audit?
- Which adapter translates the WordPress/HTTP/CLI input into that command?
- Can that adapter be called twice, re-entered, or triggered during another
  command?
- Does the handler make one domain decision, or conceal several separately
  meaningful intentions?
- Must a reaction commit with the raising change, or can it be observed later?
- Who, if anyone, consumes the handler's return value?

Every application state change enters the command bus. Use a separate
command/handler pair when the message is a stable contract or the handler has
substantial dependencies; use a self-handling command for a genuinely thin
pair. Both travel through the same middleware.

A command or synchronous domain-event handler does not dispatch another
command. Same-transaction behavior calls aggregates, repositories, or domain
services directly. A domain-event handler is appropriate when its reaction
must commit atomically with the raising command.

Command handlers normally return `void`. A scalar or DTO verdict can steer an
adapter, but it must not expose a domain object or become a hidden dependency
between domain operations. A query returns read data and never mutates state.

**Why this changes the design:** nested commands manufacture a second apparent
unit of work inside the first and make audit, transaction, and causation
semantics dishonest. A return value can create the same hidden coupling if
later domain behavior depends on it.

## Cross the time or plugin boundary

For every proposed event, ask:

- What has already happened, stated in past tense?
- Is the reaction required before the current transaction commits?
- Who owns the fact, and who merely subscribes to it?
- Can the fact be represented with scalar, reversible values?
- Are constructor parameter names and integration action names now a public
  cross-plugin wire contract?
- Can old pending outbox rows still be decoded after the proposed change?
- Does a subscriber need domain objects that only existed in the original
  request? If so, is it actually asynchronous?

Use a domain event for synchronous, same-transaction reaction. Use an
integration event for a consistency or time boundary. A rich domain event may
announce a separate scalar integration twin; a scalar event may announce
itself.

An `IntegrationListener` performs translation only: one typed integration
event becomes a command or `null`. Domain behavior belongs in the command
handler. A raw WordPress subscriber is a legitimate foreign integration, but
it gives up typed hydration and framework-managed correlation/causation.

**Why this changes the design:** asynchronous work runs in another request
against later state. Treating it as a delayed domain handler hides the new unit
of work and makes retry behavior invisible to command audit.

## Choose no orchestrator, a routine, or a LongProcess

Do not add an orchestrator merely because the work has several method calls.
Ask:

- Is the sequence fixed by code or configurable by an administrator/domain
  policy?
- Is it one business lifecycle with a durable identity, or a repeatable routine
  applied to a collection of work items?
- Does it coordinate several separate commands across time?
- Does it need to wait for an integration fact, match several arrivals,
  schedule a continuation, or compensate completed stages?
- Are retry, batching, and failed-item forks properties of the routine rather
  than new business intentions?
- Must the orchestration definition itself survive deployment and process
  restarts?

| Shape | Choose it when |
| --- | --- |
| No orchestrator | One command transaction can complete the intention; later independent consumers react to its facts. |
| `BehaviourWorkflow` routine | A stored/configurable sequence enacts behaviour kinds over deterministic work items, with batching, retry, waiting, or failed-item forks. |
| `LongProcess` | A named, developer-authored business lifecycle coordinates distinct commands across time and may schedule, await facts, resume, or compensate. |

A routine is deliberately paired with a consumer `WorkflowHandler` policy
enactor. Ask what stable behaviour kinds administrators configure, which
domain service or direct policy enacts each kind, and how
`generate_work_items()` remains deterministic. The durable cursor, results,
and item ledger live in `BehaviourWorkflow` and its repositories; handler
properties exist for the current PHP request only.

Do not turn every retry, mail send, or internal configured behaviour into a
nested command. The driving command owns one routine pass. Its paired policy
enactor may call domain services directly inside that unit of work and then
persist/reschedule the routine.

A `LongProcess` is itself durable lifecycle state. Its steps may return
commands because coordinating separate units of work is its purpose. It does
not start another process or publish an integration event directly; domain
work performed by its returned commands announces the facts that drive later
lifecycles. Waiting routes must be declared and compiled for production.

**Why this changes the design:** routines and long processes both persist
progress, but they preserve different knowledge. Confusing them either freezes
configurable policy into code or turns a named business lifecycle into a bag of
generic behaviour settings.

## Design retry and idempotency

Retry is not a property to add at the end. Ask:

- What stable key means "this is the same intent/effect again"?
- Which layer owns attempts and next-run time: outbox, routine item, process,
  command source, or external provider?
- Can execution fail after the external effect but before local success is
  recorded?
- What does the external system offer: idempotency key, lookup, conditional
  update, or no protection?
- Which failures are transient, which are terminal, and when does work enter a
  dead-letter/manual state?
- What operator action is allowed, and does it re-enter through a command?
- Can two workers claim the same work concurrently?

Use different idempotency keys for different effects even when they share one
correlation. Correlation groups a story; it does not prove that two deliveries
are duplicates.

**Why this changes the design:** at-least-once delivery moves correctness from
"the worker usually runs once" to an explicit uniqueness rule. Without a
stable key and ownership of attempt state, retries can duplicate certificates,
emails, charges, or workflow items.

## Assign consumer and module ownership

For each command, event, table, and service, ask:

- Which domain owns the invariant and data lifecycle?
- Which top-level consumer owns the prefix, tables, migrations, workers,
  retention, and dddash entry?
- Is another plugin an autonomous subscriber, or separately deployed code that
  intentionally belongs to the host domain?
- Would sharing host transaction and process state violate the other plugin's
  autonomy?
- If this is a consumer module, is its namespace a strict descendant and can it
  import the host's exact public stateful services?
- What happens to persisted process rows if the module is disabled or a class
  is renamed?

Use an integration-event contract between genuinely separate domains. Use a
0.6.2 consumer module only when the extension should be host-native: it shares
the exact host config, transaction middleware, event unit of work, outbox,
runner, tables, retention, and dashboard identity while resolving module code
from a separate compiled container.

**Why this changes the design:** namespace routing can make code look native,
but only shared runtime object identity makes its transaction and persistence
native. A lookalike transaction or runner forks operational state.

## Plan Biography and trace visibility

Ask what a future operator must be able to answer:

- Which aggregate lifecycle changes should appear in Biography?
- Which declared events carry the aggregate ID needed by `#[Touches]`?
- Which command, fact delivery, process wake, or routine item caused this work?
- Which fields require command-audit redaction?
- How long are audit, outbox, process, routine, and touches rows retained?
- Which failed, stuck, or missing consequence must be visible before a user
  reports it?
- What evidence must leave WordPress before routine retention purges it?

Correlation and causation connect work across consumer tables and plugins; do
not manually copy IDs through normal handlers. The outbox and framework
boundaries propagate trace context. The current dashboard trace is scoped to
the selected consumer; the v2 unified-trace direction can stitch those records
without a shared audit table.

Biography is a read model over declared `#[Touches]` projections. It is useful
for reconstructing aggregate change history, but it is not an event store or a
write-side authority. Annotate the integration record that is actually
published. When a rich domain event announces a separate scalar twin, put the
declaration on that twin; source annotations are not copied by `EventRouter`.
Preserve an at-rest canonical name across aggregate renames and run conformance
checks.

**Why this changes the design:** observability that is not declared while the
model is built becomes forensic guesswork later. Retention should determine
the investigation window, not whether operational data was modeled at all.

## Prove the model and rollout

Turn each decision into evidence:

- invariant tests cover accepted, rejected, and concurrent decisions;
- transaction tests prove aggregate and outbox writes commit or roll back
  together on the real connection;
- explicit event contract tests pin codec round trips, action names, and
  payload keys;
- retry tests deliver the same fact/effect more than once;
- routine tests prove deterministic item generation, batching, retry, waiting,
  and fork semantics used by the consumer;
- process tests prove start/wake/timeout/compensation routes against the dumped
  production container;
- module tests prove exact host config/service identity and one top-level
  consumer; and
- dashboard smoke proves expected Biography and trace records appear without
  leaking sensitive data.

Before a framework or wire-contract rollout, inspect every plugin on the site,
the winning package version, pending outbox payloads, active process rows, and
generated containers. A Composer constraint in one plugin does not isolate it
from the process-wide winning framework copy.

**Why this changes the design:** a model is not complete until its invariant,
boundary, replay, production container, and migration assumptions can fail in
a test or an explicit operational check.

## Provisional decision ledger

Maintain this while interviewing. Do not invent an answer to make the ledger
look complete.

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

When the developer changes one answer, re-evaluate downstream decisions. A
new transaction boundary may change event type, retry ownership, orchestration,
tests, and deployment order.

## Adversarial review

After proposing the model, attack it before implementation:

1. What state can be changed without entering the command bus?
2. What is claimed to be atomic but uses another connection or remote system?
3. Can a command or synchronous domain-event handler dispatch another command?
4. Is an integration listener performing business work rather than translation?
5. Can the same fact, hook, callback, or external effect arrive twice safely?
6. Is a domain service holding business knowledge or hiding application
   procedure?
7. Is a routine being used for a fixed business lifecycle, or a process for an
   administrator-configurable sequence?
8. Does durable state live in an aggregate/repository, or only in a handler
   property and the current PHP request?
9. Can the dumped production container discover every process and public
   listener dependency?
10. Does a sidecar accidentally create another config, transaction, event unit
    of work, runner, worker, or dashboard identity?
11. What persisted payload or process row breaks if this class or constructor
    changes?
12. Can an operator distinguish "not attempted", "retrying", "terminally
    failed", and "succeeded but acknowledgement was lost"?

Rank findings by threat to domain correctness, transaction integrity, replay
safety, and operational recovery. Do not inflate naming preferences into
architectural findings.

## Design handoff

When the boundaries survive review, present the design in this order:

### Problem and language

State the actor, one intended outcome, important domain terms, and forbidden
outcome.

### Invariant and authority

Name the deciding aggregate or policy, required state, concurrency rule, and
why the authority is sufficient.

### Command and transaction boundary

Name the command, entry adapter, physical connection, atomic writes, and
handler return contract.

### Synchronous domain work

List direct aggregate/domain-service work and any domain-event reaction that
must commit in the same transaction.

### Integration facts and later commands

List each scalar wire contract, its owner/subscribers, listener translation,
and the later command/effect.

### Routine/process decision

State why no orchestrator, a `BehaviourWorkflow` routine, or a `LongProcess`
fits. For a routine, name behaviour kinds and item identity. For a process,
name start, wait, schedule, completion, and compensation semantics.

### Failure, retry, and idempotency

Name the owner of attempt state, stable keys, transient/terminal policy,
partial-effect recovery, and operator action.

### Consumer/container ownership

Name top-level consumers, integration boundaries, any module root, exact shared
host services, and deactivation/class-rename consequences.

### Biography, trace, and operational visibility

Name touches, expected causal edges, redactions, retention, and detectable
stuck/missing consequences.

### Tests, migration, and unresolved risks

List executable proof, compatible version range, rollout ordering, persisted
data compatibility, explicit assumptions, and any decision still owned by the
developer.

Only after the developer approves this handoff should the LLM write an
implementation plan or modify consumer code.
