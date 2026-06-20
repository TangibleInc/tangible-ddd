---
name: tangible-ddd
description: Guidance for implementing DDD patterns in the Tangible ecosystem. Use when working with domain entities, events, services, commands, or any DDD architecture decisions.
---

# Tangible DDD

> Guidance for implementing DDD patterns in the Tangible ecosystem.

## Philosophy

Tangible DDD is organic. Every pattern was won through real problems, not textbook exercises. The goal: code that _feels natural_ to someone seasoned in DDD thought. No cargo-culting, but always DDD-sane.

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
Need async execution?
│
├─ Cross-context, OR must not share the originating transaction, OR
│  needs durability/retry?  (this is almost always the answer)
│  └─ Integration Event (Outbox → ActionScheduler)
│
├─ Genuinely same-context, fire-and-forget, no durability needed?
│  └─ Async Domain Event (AsyncWordpressActionHandler) — rare; see caveat
│
├─ Configurable action sequence (retry, notify, wait)?
│  └─ Behaviour Workflow
│
│  // LongProcess is pretty powerful, should be reserved for situations when integration event choreography is too difficult
└─ Multi-step with suspension, resource limits?
   └─ LongProcess
```

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

```php
// Event (extends IntegrationEvent, not DomainEvent)
class EarningIssued extends IntegrationEvent {
    public function __construct(
        public readonly Earning $earning,
        public readonly User $user,
    ) {}

    // scalarise() auto-converts entities to IDs for transport
}

// Written to Outbox, processed by OutboxProcessor, then ActionScheduler
```

**Reference:**

- `tangible-cred/src/Domain/Events/IntegrationEvent.php`
- `tangible-cred/src/Infra/Persistence/Datatables/OutboxRepository.php`

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

**When:** Configurable sequence of actions, especially for error handling (retry, notify, wait for user).

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

**When:** Multi-step business process that may suspend, resume, or hit resource limits.

**Key features:**

- Pre-emptive rescheduling (20s time limit, 90% memory threshold)
- Can await events (suspend until something happens)
- Correlation ID preserved across steps

```php
class SomeComplexProcess extends LongProcess {
    protected function step_one(): Result {
        return Result::with_payload(['gathered' => $data])
            ->with_commands([new DoSomething()]);
    }

    protected function step_two(): Result {
        // Access previous step's payload
        $data = $this->payload['gathered'];

        // Suspend until event arrives
        return Result::await(
            UserConfirmedAction::class,
            match_criteria: ['user_id' => $this->user_id]
        );
    }

    #[Async] // Forces reschedule before execution
    protected function step_three(): Result {
        return Result::complete();
    }
}
```

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
- [ ] Am I about to call `->send()` from inside a command/handler? STOP — see Hard Rules; something didn't land upstream in the modeling.
- [ ] Does this need async? It's almost certainly an integration event, not an async domain event.
- INFO: Should I inform the user that I see a new domain need or relationship emerging?
- [ ] Am I adding a new repository method? Consider query composition instead.
- [ ] Is this configurable actions? Consider Behaviour Workflow.
- [ ] Is this a long-running process? Consider LongProcess. // Consider only when palpable, multi-step business processes emerge
