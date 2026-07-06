---
name: tangible-ddd
description: Guidance for implementing DDD patterns in the Tangible ecosystem. Use when working with domain entities, events, services, commands, or any DDD architecture decisions.
---

# Tangible DDD

> Guidance for implementing DDD patterns in the Tangible ecosystem.

## Philosophy

Tangible DDD is organic. Every pattern was won through real problems, not textbook exercises. The goal: code that _feels natural_ to someone seasoned in DDD thought. No cargo-culting, but always DDD-sane.

---

## ⚠️ Upcoming: 0.2.0 Event Taxonomy (DESIGNED, NOT YET BUILT)

A hard-break redesign of the event system is fully specced. **Before writing any NEW
integration event, listener, or await code — or implementing 0.2.0 itself — read:**

- `docs/integration-event-evolution.md` (handoff — start here)
- `docs/superpowers/specs/2026-07-03-integration-event-taxonomy-and-await-mechanisms-design.md` (full spec)

Key deltas vs. the v0.1 patterns documented below (which remain accurate for code
as it exists today):

| v0.1 (below, as-built) | 0.2.0 (specced) |
|---|---|
| `IntegrationEvent` = fat domain event, `scalarise()` flattens lossily | `IntegrationEvent` = **scalar-by-definition** (reversible ctor values only); strict `scalarise()` throws; total round-trip via `from_payload()` — typed events post-AS |
| fat event crosses via entity→id flattening | fat moment implements `IAnnouncesIntegration::to_integration()` → hand-written scalar twin in `Integration\` sub-namespace (the 5 judgment lines: fact selection + naming) |
| listeners = closures in `includes/hooks/integration/` | `IntegrationListener` classes in `Application\IntegrationListeners\` (auto-wired; `get_event_class()` + `get_command(): ?ICommand`) |
| `AsyncWordpressActionHandler` for same-context deferred | **deprecated** — decomposes into `IntegrationListener` + Command (deferred work belongs under command_audit) |
| `AwaitEvent` only (1-of-1) | `IAwaitMechanism` + `AwaitAll` (fan-in: expected ids, `key_by` static extractor on the process, mandatory wall-clock timeout); `#[Awaits]` class attribute replaces YAML `awaits:` |

Even before 0.2.0 lands: **prefer scalar ctor params on new integration events** —
they'll survive the migration unchanged.

---

## Reference Implementation

The `tangible-cred` repository is the canonical reference. If you need to see how a pattern is implemented, explore it there.

**Setup (one-time):**

```bash
# From project root
git clone git@github.com:TangibleInc/tangible-cred.git .reference/tangible-cred
echo ".reference/" >> .gitignore
```

> URL: `git@github.com:TangibleInc/tangible-cred.git`

**Check if available:**

```bash
test -d .reference/tangible-cred && echo "Reference available" || echo "Not cloned"
```

When the reference is available, use it to find concrete examples before implementing patterns.

---

## Decision Trees

### Reacting to Something

```
Something happened, need to react?
│
├─ Same bounded context, must be immediate (same transaction)?
│  └─ Domain Event (sync) — handler does the work INLINE (repos/services)
│
└─ Crosses a boundary — another context, OR must NOT share the
   originating transaction (deferred / retryable / isolated)?
   └─ Integration Event (via Outbox)
```

> "Async" is a boundary, not a convenience. Deferring a reaction out of the
> originating transaction _is_ crossing a consistency boundary, which means an
> Integration Event — not an "async domain event." Reach for an async domain
> event only in the narrow case below, and read the caveat first.

### Where Does This Logic Belong?

```
New business logic needed?
│
├─ Can an Aggregate answer this alone?
│  └─ Yes → Method on Aggregate
│
├─ Needs data from multiple aggregates?
│  └─ Domain Service
│
├─ Orchestrating a use case (commands, queries)?
│  └─ Command Handler (thin, delegates to services)
│
└─ Complex eligibility/validation spanning aggregates?
   └─ Domain Service (then inject into handler)
```

### Async Patterns

```
Need async or multi-step execution?
│
├─ Cross-context, OR must not share the originating transaction, OR
│  needs durability/retry?  (this is almost always the answer)
│  └─ Integration Event (Outbox → ActionScheduler)
│
├─ Named business lifecycle whose steps belong together as one story?
│  └─ LongProcess
│
├─ Admin/user-configurable behaviour sequence, especially batched or reusable?
│  └─ Behaviour Workflow
│
└─ Genuinely same-context, fire-and-forget, no durability needed?
   └─ Async Domain Event (AsyncWordpressActionHandler) — rare; see caveat
```

---

### LongProcess vs BehaviourWorkflow

Use **LongProcess** for developer-authored named business lifecycles. It is a
process manager/saga: one place for the whole story when a process spans time,
external callbacks, side effects, waits, resource limits, and compensation.
Prefer it when integration-event choreography would hide the actual business
flow.

Example: ask a Georgia Respiratory client to present documents at an endpoint,
wait for a webhook, ping an authority endpoint, send emails, then complete or
fail the lifecycle. That belongs in one process, not scattered across a chain
of integration-event reactions.

Use **BehaviourWorkflow** for configurable behaviour execution. It is the right
tool when the action sequence may be stored as JSON, assembled by admins/users,
reused across records, batched over work items, retried, forked, or paused.

Example: Tangible Cred-style behaviour config such as retry, notify, wait for
user action, stop, or fork failed batch items into a child workflow.

---

## Hard Rules

### A command never launches a command

A command is a single intent — one trip through the bus, one transaction, one
consistency boundary. **A command handler — and equally a domain event handler
— never dispatches another command (`->send()`).**

If you ever feel the urge to launch a command from inside a command or a
handler: **stop, and treat it as a signal that something didn't land in the
modeling upstream.** The urge means you've conflated two intents, or you're
trying to reach across a boundary you haven't named yet. Re-examine before
coding around it. The reaction is always one of:

- **In-box work** (same context, same transaction) → do it _inline_ in the
  handler: hit repositories directly, or delegate to a Domain Service. To
  cascade more domain work, mutate an aggregate and let _it_ raise the next
  domain event — the publish loop drains it in the same unit of work.
- **Out-of-box work** (another context, or must not share the transaction) →
  emit an **Integration Event**. Its handler, on the far side of the Outbox,
  is the place a command gets originated.

Commands are originated only at the **edge** (an ACL translating
request → command) or by an **integration-event handler**. Never from within a
domain event handler.

**Why this isn't stylistic:** domain events publish _synchronously, inside_ the
originating command's transaction (`DomainEventsPublishMiddleware` runs within
`TransactionMiddleware`). A `->send()` from a handler re-enters the bus
mid-transaction — nesting the transaction and re-running `reset()` on the shared
`EventsUnitOfWork`. The "single intent" guarantee is gone, and failure handling
becomes incoherent (a swallowed inner error can still poison the outer commit).

---

## Patterns Reference

### Domain Events (Sync)

**When:** React immediately within same bounded context.

**Example:** User unenrolls from agency → deactivate their licenses.

```php
// Event
class UserUnenrolledAgency extends DomainEvent {
    public function __construct(
        public readonly int $user_id,
        public readonly int $agency_id,
    ) {}

    public static function name(): string {
        return 'user_unenrolled_agency';
    }
}

// Handler (sync)
class DeactivateTrackedAgencyLicensesOnUnenrol extends WordpressActionHandler {
    protected function get_event_class(): string {
        return UserUnenrolledAgency::class;
    }

    public function handle(UserUnenrolledAgency $event): void {
        // Deactivate licenses synchronously
    }
}
```

**Reference:** `tangible-cred/src/Application/DomainEventHandlers/`

---

### Domain Events (Async)

> **⚠️ 0.2.0: deprecated entirely.** `AsyncWordpressActionHandler` is removed in 0.3.0 —
> the "async domain handler" is a category error (the AS hop is another TIME, not another
> thread; serialization forces record-land regardless of intent). Its use cases decompose
> into `IntegrationListener` + Command, bringing the deferred work under command_audit.
> Do not write new ones.

> **Discouraged — prefer an Integration Event.** An "async domain event" is
> mostly a semantic convenience: it reuses a domain event's class and hook but
> runs _outside_ the originating transaction, in a separate request. Every
> property that actually matters — separate transaction, eventual consistency,
> deferred/serialized — is an _integration_ event's. The "domain" label is
> lexical, not conceptual. If you want async, you almost always want an
> Integration Event (Outbox → durability, retry, correlation, DLQ).
>
> Reach for an async domain event only when all of these hold: strictly the
> same bounded context, no durability/retry/correlation needed, and you accept
> there is no Outbox safety net. When in doubt, it's an integration event.

**When:** Same bounded context, deferred, genuinely fire-and-forget. Gets "privileged" direct access to ActionScheduler (no Outbox).

**Example:** User joins agency → retroactively issue earnings (might take a while).

```php
// Same event class, different handler base
class IssueRetroactiveEarningsOnAgencyJoin extends AsyncWordpressActionHandler {
    protected function get_event_class(): string {
        return UserEnrolledAgency::class;
    }

    public function handle(UserEnrolledAgency $event): void {
        // Runs via ActionScheduler, not blocking
    }
}
```

**Reference:** `tangible-cred/src/Infra/Services/Events/AsyncWordpressActionHandler.php`

---

### Integration Events

**When:** Cross bounded context. Needs Outbox durability (retry, DLQ, correlation).

**Example:** Earning issued → Reporting system reacts.

> **⚠️ 0.2.0: the fat example below is v0.1 style and becomes ILLEGAL** — entity ctor
> params on an `IntegrationEvent` will throw at first publish (strict `scalarise()`).
> New events: scalar ctor params only (`earning_id: int`, not `Earning $earning`);
> if in-process handlers genuinely need the entity, write a fat `DomainEvent` that
> `IAnnouncesIntegration`-announces a scalar twin. See the 0.2.0 banner above.

```php
// Event (extends IntegrationEvent, not DomainEvent)  — v0.1 AS-BUILT STYLE
class EarningIssued extends IntegrationEvent {
    public function __construct(
        public readonly Earning $earning,
        public readonly User $user,
    ) {}

    // scalarise() auto-converts entities to IDs for transport (v0.1: lossy, one-way)
}

// Written to Outbox, processed by OutboxProcessor, then ActionScheduler
```

**Consuming one — the listener is THIN; the reaction is a Command.**

An integration event arrives (Outbox → ActionScheduler → `do_action`). What
reacts to it is **not an event-handler class** — it is a one-line listener that
translates the event into an explicit Command and dispatches it. The work lives
in the command handler, on the bus, with middleware (audit / correlation /
transaction). The integration action is just *one caller* of that intent.

```php
// includes/hooks/integration/<context>.php  — split by bounded context.
// The helper wraps TangibleDDD\WordPress\integration_action(), which already
// restores CorrelationContext and strips outbox meta-keys before your callback.
tgbl_cred_integration_action(
    EarningIssued::class,
    fn($id) => ( new ReportEarningToEndpointsOnTheFlyCommand($id) )->send(),
);
```

Two rules that fall out of this:

1. **Reaction-to-an-integration-event = a Command.** This is the one place a
   command is *originated* outside the request edge (see Hard Rules). The
   listener does no domain work — it builds a command and `->send()`s it.
2. **Listeners live in `includes/hooks/integration/`, by bounded context** —
   not in `Application/EventHandlers/` next to domain-event handlers. They are
   wiring, not handlers.
   *(⚠️ 0.2.0: hook-file closures are superseded by `IntegrationListener` classes
   in `Application\IntegrationListeners\` — same thinness, but typed events,
   auto-wiring, causation-correct ceremony, and dashboard-enumerable topology.
   The thin-listener PRINCIPLE is unchanged; only the vessel changes.)*

> **Anti-pattern (do not do this):** a `class FooHandler implements IEventHandler`
> that registers `add_action(SomeEvent::integration_action(), ...)` in its own
> constructor and performs the work (HTTP, persistence, classification) inside
> the closure. That welds an *intent* to a single trigger, hides the command
> entirely (nothing else — bulk replay, manual re-run, a test — can invoke it),
> and re-implements the framework's correlation/meta-key handling by hand.
> **Delivering, reporting, notifying are intents → Commands.** Being triggered
> by an integration action is incidental. If you catch yourself putting logic in
> the `add_action` closure, the command you actually needed is missing.

One caveat when the transport needs the *outcome* (e.g. throw to make
ActionScheduler retry on a retryable failure): the command **records its result
and returns a verdict — it does not throw for retry**. A throw inside the
command's `TransactionMiddleware` transaction rolls back the very row you just
wrote. Provoke the retry in the *listener*, after `->send()` returns:

```php
tds_integration_action(EventReadyForDelivery::class, function (array $payload) {
    $event   = EventReadyForDelivery::from_args($payload);
    $verdict = ( DeliverEventCommand::from_event($event) )->send();
    if ($verdict === DeliveryVerdict::Retryable) {
        throw new RetryableDeliveryException(/* … */);   // transport concern, outside the txn
    }
});
```

**Reference:**

- `tangible-cred/src/Domain/Events/IntegrationEvent.php`
- `tangible-cred/src/Infra/Persistence/Datatables/OutboxRepository.php`
- `tangible-cred/includes/hooks/integration/` — thin listeners, by context (the canonical shape)
- `ddd-wordpress/integration-events.php` — `integration_action()` (correlation restore + meta-key strip)

---

### Domain Services

**When:** Business logic needs multiple aggregates or can't live on one aggregate alone.

**Pattern:** Extract from command handler when it's "doing too much."

```php
// Service
class AccreditationEligibilityService implements IDomainService {
    public function __construct(
        private IAccreditationRepository $accreditations,
        private IEarningRepository $earnings,
        private IUserRepository $users,
    ) {}

    public function get_user_eligible_accreditations_for_content(
        int $user_id,
        int $content_id
    ): AccreditationEligibilityResult {
        // Complex eligibility logic here
    }
}

// Handler stays thin
class IssueContentRelatedAccreditationsHandler {
    public function handle(IssueCommand $command): void {
        $eligible = $this->eligibility_service
            ->get_user_eligible_accreditations_for_content($user_id, $content_id);

        foreach ($eligible as $accreditation) {
            $this->issuance_service->issue($accreditation);
        }
    }
}
```

**Reference:** `tangible-cred/src/Domain/Services/AccreditationEligibilityService.php`

---

### Behaviour Workflows

**When:** Configurable sequence of actions, especially when admins/users may
stitch behaviour together or when the system needs a reusable operational
ledger over work items (retry, notify, wait for user, stop, fork failed items).

BehaviourWorkflow is not just "async work." It is for behaviour configuration
and batch/item execution where the sequence itself may be data.

```php
// Configuration (stored as JSON)
$behaviours = [
    new RetryBehaviourConfig(max_attempts: 3, delay: 300),
    new NotificationBehaviourConfig(template: 'api_failure'),
    new UserTriggeredRetryBehaviourConfig(prompt: 'Please update your email'),
];

// Workflow aggregate tracks execution
$workflow = new BehaviourWorkflow($behaviours);
$workflow->maybe_advance($executionResult);
```

**Reference:** `tangible-cred/src/Domain/BehaviourWorkflow.php`

---

### LongProcess

**When:** Developer-authored multi-step business process that should read as
one named lifecycle and may suspend, resume, hit resource limits, or compensate.

**Key features:**

- Pre-emptive rescheduling (20s time limit, 90% memory threshold)
- Can await events (suspend until something happens)
- Correlation ID preserved across steps
- Keeps the process story in one class instead of spreading it across
  integration-event choreography
- Step discovery is intentionally source-order driven: protected methods that
  return `Result` execute in the order they appear in the file

```php
class SomeComplexProcess extends LongProcess {
    protected function step_one(): Result {
        return new Result(
            payload: new SomePayload(gathered: $data),
            commands: [new DoSomething()],
        );
    }

    protected function step_two(SomePayload $payload): Result {
        // Access previous step's payload
        $data = $payload->gathered;

        // Suspend until event arrives
        return new Result(
            payload: $payload,
            await: new AwaitEvent(
                UserConfirmedAction::class,
                match_criteria: ['user_id' => $this->user_id],
            ),
        );
    }

    #[Async] // Forces reschedule before execution
    protected function step_three(SomePayload $payload, UserConfirmedAction $event): Result {
        return new Result();
    }
}
```

Source-order reflection is a deliberate developer-experience choice: a process
class should read top-to-bottom like the business lifecycle. Treat method
reordering as a behaviour change, not a cosmetic refactor. Keep helper methods
from returning `Result` unless they are actual process steps.

**Reference:**

- `tangible-cred/src/Application/Process/LongProcess.php`
- `tangible-cred/src/Application/Process/ProcessRunner.php`

---

### Value Objects (JSON Lifecycle)

**When:** Complex value that needs JSON serialization for storage (e.g., postmeta).

```php
class SlottedTimeValue extends JsonLifecycleVO {
    public function __construct(
        private array $slots, // TimeSlot[]
    ) {}

    public function get_value_at(DateTimeImmutable $date): mixed {
        foreach ($this->slots as $slot) {
            if ($slot->contains($date)) {
                return $slot->get_value();
            }
        }
        return null;
    }

    // Inherited: to_json(), from_json_instance()
}
```

**Reference:** `tangible-cred/src/Domain/ValueObjects/DateBasedValues/`

---

### Query Composition (IReferencesUsers)

**When:** Avoiding repository method explosion for filtered queries, especially when cross-repository joins are involved.

```php
// Instead of: findByUserId(), findByUserIds(), findByAgencyUsers()...

// UserRepository returns composable query
$userQuery = $userRepo->getQueryForAgencyUsers($agencyId); // implements IReferencesUsers

// EarningsRepository accepts any user-referencing query
$earnings = $earningsRepo->findByUserQuery($userQuery);
```

**Reference:** `tangible-cred/src/Infra/Persistence/Select/`

---

### Anti-Corruption Layer (WordPress Forms)

**When:** WordPress admin forms need to trigger domain commands.

**Pattern:** `tcred_aggregate_post_primer` — a factory that wires `save_post` to your domain.

```php
// Hook a post type to a command
tcred_aggregate_post_primer(
    'tgbl-cred-accred',                        // WP post type
    CreateOrUpdateAccreditationCommand::class, // Domain command
    'tgbl_save_accreditation_from_input'       // Translator function
);

// Translator: $_REQUEST → Command
function tgbl_save_accreditation_from_input($post_id): CreateOrUpdateAccreditationCommand {
    // Parse JSON from form fields
    $issuance_input = json_decode(wp_unslash($_REQUEST['issuance_schedule'] ?? '[]'), true);

    // Build typed DTOs
    $issuance_schedule = new TimedIssuanceSlotDTOCollection();
    foreach ($issuance_input as $slot) {
        $issuance_schedule->add(new TimedIssuanceSlotDTO(...));
    }

    // Return domain command — domain never sees $_REQUEST
    return new CreateOrUpdateAccreditationCommand(
        accreditation_id: $post_id,
        agency_id: (int) ($_REQUEST['agency_id'] ?? 0),
        issuance_schedule: $issuance_schedule,
    );
}
```

**What the primer handles:**

- Hooks `save_post` for the post type
- Calls translator → builds Command → sends to domain
- Catches exceptions → displays as WP admin notices
- Reverts post to draft on error
- Flashes form fields so user doesn't lose input

**The rule:** Only the translator function touches `$_REQUEST`. Domain stays clean.

**Reference:** `tangible-cred/includes/posts/hooks/aggregate-post-primer.php`

---

### Read Models (Query/QueryHandler)

**Pattern:** Lightweight CQRS — same tables, separated code paths.

```
Write: Command → CommandHandler → Aggregate → Repository
Read:  Query   → QueryHandler   → Repository (or raw SQL) → DTO
```

```php
// Query object
class GetUserEarningsQuery implements IQuery {
    public function __construct(
        public readonly int $user_id,
        public readonly ?int $agency_id = null,
    ) {}
}

// Handler returns DTO, not entities
class GetUserEarningsQueryHandler {
    public function handle(GetUserEarningsQuery $query): UserEarningsDTO {
        // Can use repository, raw $wpdb, whatever fits
        return new UserEarningsDTO(...);
    }
}
```

**Tangible DDD doesn't prescribe advanced read model patterns.** Per your needs, consider:

- Dedicated projection tables (denormalized for specific queries)
- Materialized views
- Procedural functions with raw queries

The Query/QueryHandler pattern gives you intent separation without infrastructure overhead. Scale up when you need to.

**Reference:** `tangible-cred/src/Application/Queries/`

---

## LMS Implementation Docs

For LMS-specific implementation recipes and project structure, see:

- **[Project Structure](plugins/lms/docs/architecture/project-structure.md)** — directory layout, naming conventions, command bus pipeline, DI registration, CLI commands
- **[DDD Recipes](plugins/lms/docs/architecture/ddd-recipes.md)** — step-by-step guides for adding domain events, commands, queries, repositories, Doctrine entities, and aggregates

Always consult these docs before implementing new domain elements. They contain the exact base classes, namespaces, and conventions used in this project.

---

## Naming Conventions

| Suffix            | Meaning                            |
| ----------------- | ---------------------------------- |
| `*Service`        | Domain service (business logic)    |
| `*Handler`        | Command/Query/Event handler        |
| `*Repository`     | Data access                        |
| `*Workflow`       | Behaviour workflow                 |
| `*Process`        | LongProcess                        |
| `*VO` or `*Value` | Value object                       |
| `*Result`         | Operation result (success/failure) |
| `*DTO`            | Data transfer object               |
| `*Response`       | Query handler return DTO           |
| `*Entity`         | Doctrine ORM entity (infra only)   |
| `*Metadata`       | JSON metadata value object         |

---

## Command Audit & Correlation

Every command gets:

- `command_id` — unique ID for this execution
- `correlation_id` — traces entire chain across async boundaries

```php
// Access in handlers/services
$correlationId = CorrelationContext::get();
$commandId = CorrelationContext::get_command_id();
```

Integration events preserve correlation through Outbox.

**Reference:** `tangible-cred/src/Application/Correlation/CorrelationContext.php`

---

## Quick Checklist

Before implementing a new feature:

- [ ] Is this a new aggregate? Does it have a true identity and lifecycle?
- [ ] Is this logic on the right aggregate, or does it need a domain service?
- [ ] Am I reacting to something? Which event type? (Reaction = inline work in a handler, OR an integration event — never a command dispatched from a handler.)
- [ ] Am I about to call `->send()` from inside a command/handler? STOP — see Hard Rules; something didn't land upstream in the modeling. (Exception: a *thin integration listener* in `includes/hooks/integration/` — not a handler — IS where a command is legitimately originated.)
- [ ] Am I consuming an integration event with a fat `IEventHandler` whose closure does the work? STOP — the listener is thin; the work is a Command. The intent was hiding. See Integration Events.
- [ ] Does this need async? It's almost certainly an integration event, not an async domain event.
- INFO: Should I inform the user that I see a new domain need or relationship emerging?
- [ ] Am I adding a new repository method? Consider query composition instead.
- [ ] Is this a named business lifecycle that should be understood in one place? Consider LongProcess.
- [ ] Would integration-event choreography hide the real process story? Prefer LongProcess.
- [ ] Is this configurable/admin-authored behaviour, especially over batches or reusable actions? Consider BehaviourWorkflow.
- [ ] Am I using BehaviourWorkflow just because it is async? STOP — decide whether this is user-configured behaviour or a named process.
