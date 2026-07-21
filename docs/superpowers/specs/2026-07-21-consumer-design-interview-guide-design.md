# Consumer Design Interview Guide

**Date:** 2026-07-21

**Status:** Approved design; implementation pending written-spec review

## Purpose

Add a current Tangible DDD document that helps a consumer developer's LLM ask
the developer the questions needed to discover a sound model before changing
code. This is not another API reference and not a generic DDD glossary. Its job
is to turn an underspecified feature request into an explicit, reviewable
boundary design.

The guide must teach through inquiry. It should make the developer explain the
business invariant, authority, transaction boundary, failure semantics, time
boundary, and ownership before the LLM chooses commands, events, routines,
processes, repositories, or modules.

## Chosen Shape

Use a hybrid of two modes:

1. **Progressive interview:** ask one high-information question at a time,
   adapt the next question to the answer, and maintain a provisional model.
2. **Adversarial review:** after a candidate design exists, attack ambiguous
   ownership, false atomicity, hidden writes, unsafe retries, accidental
   coupling, and unnecessary orchestration.

A checklist alone is easy to ignore and does not teach. Embedding the entire
question bank in the canonical skill would make the skill unwieldy. The
canonical skill will therefore contain a short trigger and interview protocol,
then link to a dedicated `docs/consumer-design-interview.md` guide.

## Skill Integration

The canonical `.claude/skills/tangible-ddd/SKILL.md` will tell an LLM to enter
design-dialogue mode when a requested change leaves one or more consequential
questions unresolved, especially when it includes:

- a WordPress hook or REST callback that mutates state;
- more than one aggregate, repository, plugin, or persistence connection;
- retries, waiting, scheduling, batches, compensation, or manual intervention;
- a new integration event or a change to an existing wire shape;
- uncertainty between a command, domain service, domain event, integration
  event, behaviour routine, or `LongProcess`; or
- separately deployed code that wants to behave as part of a host consumer.

The skill will prescribe these conversational rules:

- ask one question at a time when the answer can materially change the design;
- briefly state why a question matters when its consequence is not obvious;
- keep a provisional decision ledger and revise it when answers conflict;
- challenge contradictions directly instead of silently choosing an
  interpretation;
- stop interviewing once the material boundaries are clear;
- summarize the proposed model and obtain approval before implementation; and
- do not block a narrow change whose intent and boundaries are already exact.

The full question bank stays in the linked guide.

## Interview Sequence

The guide will organize questions as a funnel, not as a questionnaire that
must always be exhausted.

### 1. Business outcome and language

Establish the actor, intended outcome, domain terms, success condition, and
what must never become possible. Separate a business rule from UI, transport,
or WordPress-hook mechanics.

### 2. Authority and invariant

Identify the aggregate or other authority allowed to decide the change, the
state it must inspect, the invariant it protects, the relevant concurrency
case, and whether a proposed domain service is knowledge or merely procedure.

### 3. Transaction boundary

Ask which writes must either all commit or all roll back, which database
connections actually participate, and what remains correct if execution dies:

- before the command transaction begins;
- after the domain write but before commit;
- after commit but before an asynchronous consequence; and
- during a retry after a partial external effect.

The LLM must distinguish desired atomicity from atomicity the infrastructure
can really provide. It must not place a remote API, email, or a second database
inside an imaginary shared transaction.

### 4. Command and synchronous work

Name one user/system intent as one command. Decide whether the handler invokes
an aggregate, repository, or domain service inside the same unit of work.
Synchronous domain-event reactions are allowed only when they must commit with
the raising command; they perform work directly and do not dispatch another
command.

### 5. Facts and time boundaries

Ask what happened, who outside the current consistency boundary needs to know,
whether the record is scalar and reversible, and whether its constructor keys
are now a cross-plugin wire contract. Integration listeners translate a fact
into a later command; they do not contain domain behavior.

The guide uses public terms such as command, domain event, integration event,
routine, lifecycle, correlation, and causation. It does not expose the
framework's internal act/fact/trajectory ontology as the primary consumer
vocabulary.

### 6. Orchestration choice

Force a reasoned choice among:

- no orchestrator: one command completes the intent;
- `BehaviourWorkflow`: a stored/configurable routine over work items, with a
  consumer-owned `WorkflowHandler` policy enactor, batches, retry, wait, or
  failed-item forks; and
- `LongProcess`: a developer-authored named business lifecycle that coordinates
  separate commands across time, can schedule, await facts, resume, and
  compensate.

The deciding questions concern authorship, variability, lifecycle identity,
work-item cardinality, waiting, and compensation. Retries or emails are not
automatically commands inside a routine; the driving command remains the
outer write boundary and `execute_one()` may enact domain-service knowledge.

### 7. Reliability and idempotency

Ask what makes repeated delivery safe, which key identifies the same intent or
fact, where retry state lives, what is terminal, how poison work is surfaced,
and what an operator can do without bypassing the command boundary.

### 8. Ownership and integration topology

Identify the top-level consumer, namespace root, prefix, and table owner. For
cross-plugin behavior, decide whether the relationship is an integration-event
contract between domains or a consumer module that deliberately shares one
host identity. A separately deployed module must still satisfy the 0.6.2
container, service-bridge, lifecycle, and deactivation contract.

### 9. Read model and observability

Ask what operators need to reconstruct later:

- which aggregate lifecycle changes merit `#[Touches]` and Biography;
- which causal edges should appear in a trace;
- which payloads require redaction;
- how much audit/outbox/process/workflow data is retained; and
- what stuck, failed, or missing consequence must be detectable.

Correlation is propagated context, not a substitute for domain identity.
Biography is a rebuildable declared-change view, not an event store.

### 10. Proof and rollout

Turn the decisions into tests: invariant tests, transaction rollback, event
round trips, idempotent retry, dumped-container process discovery, module
bridge identity, migration compatibility, and dashboard/trace smoke. Account
for already-persisted outbox payloads and process class names during rollout.

## Provisional Decision Ledger

During the interview, the LLM maintains a compact mutable ledger:

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

The ledger is a reasoning aid, not an artifact to dump after every answer. The
LLM should show only the changed or disputed portion during the conversation
and present the complete version when proposing the design.

## Final Handoff

The guide ends with a reusable output schema for the approved design:

1. **Problem and language**
2. **Invariant and authority**
3. **Command and transaction boundary**
4. **Synchronous domain work**
5. **Integration facts and later commands**
6. **Routine/process decision**
7. **Failure, retry, and idempotency**
8. **Consumer/container ownership**
9. **Biography, trace, and operational visibility**
10. **Tests, migration, and unresolved risks**

The LLM must label assumptions and unresolved decisions. It must not smuggle a
choice into a class diagram or implementation plan before the developer has
accepted it.

## Adversarial Review Pass

The closing review asks counterfactual questions, including:

- What state can now be changed without entering the command bus?
- What is claimed to be atomic but uses another connection or remote system?
- Can a command or synchronous handler dispatch another command?
- Is an integration listener doing business work rather than translation?
- Can the same fact or scheduled callback arrive twice safely?
- Is a workflow being used for a fixed business lifecycle, or a process for an
  admin-configurable routine?
- Does a domain service contain a real business decision, or hide application
  orchestration?
- Can a dumped production container discover every process?
- Does a sidecar accidentally create another config, transaction, runner,
  worker, or dashboard identity?
- Can operators understand the outcome after trace/audit retention expires?

Findings must be ranked by the risk of violating domain correctness,
transaction integrity, replay safety, or operational recoverability.

## Documentation and Test Changes

Implementation will:

1. Add `docs/consumer-design-interview.md` as a current document.
2. Add it to the current section of `docs/README.md` and the documentation list
   in the package `README.md`.
3. Add a concise design-dialogue section and link to the canonical skill.
4. Add the guide to `DocumentationCurrentnessTest::OPERATIONAL` so removed APIs
   and broken local links cannot silently return.
5. Run the skill validator and a fresh pressure test in which an agent receives
   an ambiguous consumer feature request. Success means it asks material
   boundary questions before prescribing classes and then produces the agreed
   handoff shape without inventing removed APIs.

## Non-goals

- A universal DDD tutorial or exhaustive pattern catalog.
- A mandatory questionnaire for every code edit.
- Automatic generation of production classes from incomplete answers.
- Exposing internal ontology labels as required consumer vocabulary.
- Replacing source inspection, the wiring guide, module guide, or release
  ledger.
