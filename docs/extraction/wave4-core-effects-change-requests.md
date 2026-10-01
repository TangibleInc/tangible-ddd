# Wave 4: core-effects change requests

Author: core-effects (wave 4). Branch `wave4/core-effects`. Owned paths: `packages/ddd-core/src/**` except `Application/Process/**`, `Runtime/Process/**`, `Application/BehaviourWorkflows/**`, `Defaults/Pdo/**`; `packages/ddd-core/tests/Unit/**` except tests for those paths. Binding inputs: [contract-register.md](contract-register.md) (3.8, 3.9, 3.11, 4, 5.1, 8 wave 4), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md), [wave3-notes.md](wave3-notes.md) (CR-PDO-6 ruling, TXP demands L1-L8), [wave3-pdo-compose-change-requests.md](wave3-pdo-compose-change-requests.md) (CR-PC-2), [wave2-symfony-adapters-change-requests.md](wave2-symfony-adapters-change-requests.md) (CR sf-8).

Every item is additive: a new interface, a new class, an optional trailing parameter, a new optional property, or a docblock. No ratified interface lost or changed a method. Section "Behaviour changes" lists what an existing caller can observe.

## What this round did

| Task | Where | Tests |
|---|---|---|
| D1 `EffectMiddleware` over the wave-1 ports | `Runtime/Effects/{EffectMiddleware,RecordEffect,NoEffectJournal,EffectInsideTransaction}` | `tests/Unit/Runtime/EffectMiddlewareTest` |
| D1 failure command fired by the core invoker, deterministic id | `Runtime/Delivery/SubscriptionRegistrar` (onExhausted) | `EffectMiddlewareTest::test_budget_is_counted_in_the_delivery_ledger_and_the_failure_command_fires_once`, `SubscriptionRegistrarTest` |
| D1 in-memory journal double | `Testing/InMemoryEffectJournal` (existing; docblock) | `EffectMiddlewareTest` |
| D12 per-command audit policy | `Runtime/Audit/{Audit,AttributeAuditPolicy}`, `CorrelationMiddleware` default | `tests/Unit/Runtime/AuditPolicyTest` |
| D8 redaction extension point | `Application/Logging/Redactor`, `Runtime/Audit/{Sensitive,NotAudited}`, `CorrelationMiddleware` | `tests/Unit/Application/RedactorExtensionTest` |
| D6 large strings, cap, quarantine | `Runtime/Codec/{LargeString,PayloadTooLarge,UndecodableLargeString,UnencodablePayload}`, `IntegrationBehaviour`, `OutboxConfig`, `OutboxIntegrationEventBus` | `tests/Unit/Runtime/LargeStringCodecTest` |
| L1 `IReturningCommandHandler` | `Application/CommandHandlers/IReturningCommandHandler`, `SelfExecutingCommandMiddleware` guard | `tests/Unit/Application/TxpApiDemandsTest` |
| L2 identity-agnostic root | `Domain/Shared/{IAggregateRoot,AggregateRoot,CanonicalName}`, `Infra/Persistence/Shared/{AggregateRootRepository,PersistsAggregatesRepository}`, `Domain/Events/Touches` | `tests/Unit/Domain/AggregateRootTest` |
| L3 translator hooks typed `class-string` | `Application/EventHandlers/IntegrationTranslator` (docblock) | `TxpApiDemandsTest` |
| L7 `NotPermittedException` | `Domain/Exceptions/NotPermittedException` | `TxpApiDemandsTest` |
| D11 receipt clarification | `SelfHandlingCommand`, `IReturningCommandHandler` (docblocks) | - |
| CR-PDO-6 core rule (lease-expired re-claims) | `Runtime/Outbox/IReportsClaimDeadLetters`, `Testing/InMemoryOutboxStore`, `Infra/Services/{OutboxProcessor,ProcessingResult}` | `tests/Unit/Testing/LeaseExpiryReclaimTest` |
| CR-PC-2 (optional) fact class on the record | `Runtime/Outbox/OutboxRecord`, `OutboxIntegrationEventBus` | `OutboxIntegrationEventBusCoreTest` |

## CR-W4CE-1: `EffectMiddleware` and `RecordEffect` (D1)

- **What.** `final class Runtime\Effects\EffectMiddleware implements League\Tactician\Middleware`, `__construct(?IEffectJournal $journal = null, ?ITransactionBoundary $boundary = null)`. Both resolve from `HostDefaults` when null. Placed between Correlation and Transaction: `Correlation → Effect → Transaction → DomainEventsPublish → SelfExecuting → handler`.
- **Behaviour for an `IExternalEffectCommand`:** `find(idempotencyKey())`. With no entry, `perform()` runs outside any transaction and `store()` runs at once, in its own autocommit, so the entry survives a rolled-back `record()`. The rest of the onion then receives `new RecordEffect($command, $result)`. A retry finds the entry and skips `perform()`. Re-dispatching under a new command id does the same. The only way to perform again is `IEffectJournal::invalidate()` in the repair command's own transaction. The bus returns the `EffectResult` (D11). Any other command passes through.
- **`RecordEffect`** (new, `final`): `extends SelfHandlingCommand implements ITransactionalCommand`. Its `handle()` calls `$effect->record($result)`. It also attaches the act's `EventsUnitOfWork` when the effect command is itself a `SelfHandlingCommand`. Because it is transactional, `record()` always runs inside the Transaction middleware's unit of work, and domain events recorded there publish in that transaction. `apply(): EffectResult` is the public entry for terminals without a SelfExecuting stage (a handler map routes `RecordEffect::class => fn ($r) => $r->apply()`). `send()` throws `\LogicException`.
- **New exceptions:** `NoEffectJournal` (`\LogicException`, before `perform()`) and `EffectInsideTransaction` (`\LogicException`, before `perform()` when the boundary reports an open transaction). An empty idempotency key throws `\InvalidArgumentException`.
- **Budget.** Nothing is counted in the middleware. A fact-triggered effect fails its subscriber in `IntegrationDelivery`, the delivery ledger counts the attempts, and at the budget `Subscriber::onExhausted` fires `failureCommand()`. `SubscriptionRegistrar` already wired that in wave 2. The middleware never looks at Messenger or transport events.
- **Why this shape.** The register puts EffectMiddleware between Correlation and Transaction and `record()` "inside the Transaction middleware". A single middleware can only get `record()` inside the transaction by sending a transactional message down the onion. The act bracket stays outside, so the audit row and command id belong to the effect command.
- **Compatibility.** New classes only. Hosts opt in by adding the middleware to their bus.

## CR-W4CE-2: deterministic id for the D1 failure command

- **What.** The `onExhausted` closure that `SubscriptionRegistrar::registerListener()` builds now sends `failureCommand()` inside `DeterministicCommandId::within(uuid5(event_id, "{subscriber_id}#failure"))`. The event id comes from `Correlation::current_fact()`. A null failure command still does nothing.
- **Why.** `IntegrationDelivery` re-fires the compensation when the process died between the callback and the ledger's terminal marker (it is at-least-once). A stable id lets the failure command dedup.
- **Compatibility.** Observable only as the failure command's `command_id` (it was random before).

## CR-W4CE-3: `#[Audit]` and `AttributeAuditPolicy` (D12)

- **What.** New attribute `Runtime\Audit\Audit(bool $enabled = true, bool $parameters = true)` (class target; the nearest declaration on the class chain wins). New `final class AttributeAuditPolicy implements IAuditPolicy` with `__construct(array $notAudited = [], array $withoutParameters = [])`. The two lists hold class-strings matched with `instanceof`, so a parent or a marker interface covers a whole family. The `CorrelationMiddleware` fallback is now `AttributeAuditPolicy` (constructor > `HostDefaults` > `AttributeAuditPolicy`; it was `AuditEverything`). `AuditEverything` is unchanged.
- **Guards kept.** The nesting guard runs before the policy, as before. `AuditPolicyTest::test_the_nesting_guard_holds_for_an_unaudited_command` and the existing `cmd.guards-without-audit` cover it.
- **Requests.** sf: bind `tangible_ddd.audit.policy` to `AttributeAuditPolicy` (today `AuditEverything`, which ignores `#[Audit(false)]`), and optionally expose the two lists as bundle config. wp/pdo: no change is needed unless they provide an `IAuditPolicy` in `HostDefaults`.

## CR-W4CE-4: `Redactor` extension point (D8)

- **What.** `Redactor::__construct(array $extraKeys = [], ?\Closure $isSensitive = null)`, the signature the register sketched. `$isSensitive(string $path, mixed $value): bool` receives dotted paths (`card.number`, `items[0].token`). New `redact_object(object $command): array{0: array, 1: list<string>}` reads the command's public properties and honours the property attributes `Runtime\Audit\Sensitive` (mask) and `Runtime\Audit\NotAudited` (omit). Both attributes also target promoted constructor parameters. `CorrelationMiddleware` now calls `redact_object($command)` instead of `redact(get_object_vars($command))`.
- **Built-in rules added:** a key containing `password`, `passwd` or `secret`, or ending in `token`, is masked. So are `private_key`, `secret_key`, `apikey`, `pem` and `credentials`. A PEM block is replaced by `{__redacted: pem, label, length}` whatever its key. A binary string (invalid UTF-8 or a NUL byte) becomes `{__summary: binary, length, sha256}` with no preview. A sensitive binary value masks to `[secret]`.
- **Compatibility.** `Redactor` stays `final`; `new Redactor()` and `redact(array)` are unchanged. See "Behaviour changes" for the wider masking.

## CR-W4CE-5: `LargeString` codec, caps and quarantine (D6)

- **What.** `Runtime\Codec\LargeString(string $value, int $maxBytes = 4 MiB)` (`final`, `\Stringable`). The constructor throws `PayloadTooLarge` over the cap. It has `toPayload()` and `fromPayload(mixed $raw, ?int $maxBytes = null)`. The stored form is `{__ddd_large_string: 1, encoding: base64, bytes, max_bytes, sha256, data}`. `fromPayload()` throws `UndecodableLargeString`, whose `public readonly string $quarantineReason` is the text a host stores in `quarantine_reason`, on a wrong shape, bad base64, a length or sha256 mismatch, or a value over the cap.
- `PayloadTooLarge extends \DomainException` (register name) carries `subject`, `bytes` and `maxBytes`.
- `UnencodablePayload extends \DomainException` (new): the payload is not JSON-encodable, typically a binary string in a plain `string` field. It is thrown at append.
- `IntegrationBehaviour` encodes a `LargeString` constructor parameter (nullable allowed) and revives it by type.
- `OutboxConfig` gains a trailing `int $max_payload_bytes = OutboxConfig::DEFAULT_MAX_PAYLOAD_BYTES` (8 MiB; 0 disables the cap). The port form of `OutboxIntegrationEventBus` checks the JSON-encoded payload against it before `IOutboxStore::append()`, so the command fails before commit. The 0.6 repository form is not guarded, because its repositories encode with their own rules (wp repairs invalid UTF-8) (R2).
- **Requests.** Process-state codecs (core-process for `Application/Process`, pdo `ProcessCodec`, wp, sf) should revive `LargeString` fields and map `UndecodableLargeString::$quarantineReason` to `quarantine_reason` (status `failed`, R5) for `decode.unknown-class` / `codec.large-payload`. sf: column types for the 8 MiB payload (Postgres `text`/`jsonb` already fit). conformance: `codec.large-payload` on mem can be built from `LargeStringCodecTest`'s first and fourth cases.

## CR-W4CE-6: `IReturningCommandHandler` (L1)

- **What.** `interface Application\CommandHandlers\IReturningCommandHandler { public function handle(ICommand $command): mixed; }`. It is a sibling of `ICommandHandler`, not a subtype. `SelfExecutingCommandMiddleware`'s "self-handling command wraps a handler" guard now refuses an injected `IReturningCommandHandler` as well.
- **Request (sf).** `TangibleDddBundle` autoconfigures `ICommandHandler` with `DddTags::COMMAND_HANDLER`. Add `registerForAutoconfiguration(IReturningCommandHandler::class)->addTag(DddTags::COMMAND_HANDLER)`. Also extend `HandlerLocatorPass:111`'s `is_a(..., ICommandHandler::class)` check to the new interface. Tactician's `CommandHandlerMiddleware` and pdo's `HandlerMiddleware` already return the handler's value.

## CR-W4CE-7: identity-agnostic aggregate root (L2)

- **What.**
  - `interface Domain\Shared\IAggregateRoot extends IRecordsDomainEvents { public static function canonical_name(): string; }`.
  - `trait CanonicalName` holds the default implementation.
  - `abstract class AggregateRoot implements IAggregateRoot`: `RecordsDomainEvents` plus `CanonicalName`, with no id.
  - `Aggregate` (0.6, int id) now also implements `IAggregateRoot`; its body is unchanged.
  - `abstract class Infra\Persistence\Shared\AggregateRootRepository`: `final save(IRecordsDomainEvents)`, `abstract persist(IRecordsDomainEvents)`, no `get_by_id`.
  - `PersistsAggregatesRepository::save()` now takes `IRecordsDomainEvents`. The method is final, and widening the parameter is contravariant against `IPersistsAggregates::save(Aggregate)`. The class check against `get_aggregate_class()` still guards it, and a 0.6 subclass with `persist(Aggregate)` works unchanged.
  - `#[Touches]` accepts any `IAggregateRoot`.
- **Why two repository bases.** `IPersistsAggregates::get_by_id(int): ?Aggregate` cannot be satisfied by a uuid root, so `PersistsAggregatesRepository` cannot fully serve one. Widening `save()` is what L2 asked for. `AggregateRootRepository` is the base TXP should use.
- **Compatibility.** `Entity`, `Aggregate`, `IPersistsAggregates` and the abstract `persist(Aggregate)` signature are unchanged (R2/R3).

## CR-W4CE-8: `IntegrationTranslator` hooks (L3), `NotPermittedException` (L7), D11 docblock

- L3: `get_event_class()` and `event_class()` are documented `@return class-string` ("the fact class or marker interface"). This is a docblock change only.
- L7: `class Domain\Exceptions\NotPermittedException extends BusinessConstraintException` (not final, so contexts extend it).
- D11: `SelfHandlingCommand` and `IReturningCommandHandler` now say that a receipt is built when `handle()` returns. That is before `DomainEventsPublishMiddleware` drains the recorded events into their in-transaction reactions, so a receipt can never carry what a reaction creates.

## CR-W4CE-9: expired-lease re-claims count as relay attempts (CR-PDO-6 core rule)

- **What.** New `interface Runtime\Outbox\IReportsClaimDeadLetters { const LEASE_EXPIRED_ERROR = '...'; public function takeDeadLetteredAtClaim(): array; }`. It returns a `list<array{0: Claim, 1: string}>`. The constant text and the method shape are identical to `DbalPostgresOutboxStore`'s. The interface docblock states the rule:
  - a re-claim of an expired lease is an attempt (`attempts + 1`, `last_error` = the constant), and `Claim::$attempts` includes it;
  - a re-claimed row that reaches `max_attempts` is moved to the DLQ inside `claim()` and is not handed out.
  - `InMemoryOutboxStore` implements it.
- **Relay step.** `OutboxProcessor` port form calls `takeDeadLetteredAtClaim()` after every claim when the store implements the interface. For each row it logs `DLQ`, emits `OutboxDeadLettered` and lists the event id in the new trailing `ProcessingResult::$deadLetteredAtClaim`. These rows are not counted in `dlq`/`total` and are not in `claimed`. The operator view lists them through `deadLetters()` (relay layer), with attempts equal to the budget.
- **Requests.**
  - sf: make `DbalPostgresOutboxStore` implement `IReportsClaimDeadLetters` (the method already matches), then drop `Relay::signalClaimDeadLetters()` and use `RelayReport::of($r, $r->deadLetteredAtClaim)`. If both stay, `OutboxDeadLettered` fires twice. Until sf adopts the interface, core does nothing for sf.
  - pdo and wp: implement the rule in their `claim()` (CR-PDO-6 ruling).
  - conformance: `relay.lease-fencing` gains the assertion on mem, which can use `LeaseExpiryReclaimTest`'s cases.

## CR-W4CE-10: `OutboxRecord::$event_class` (CR-PC-2, optional request accepted)

- **What.** `OutboxRecord::__construct()` gains a trailing `?string $event_class = null`. `OutboxIntegrationEventBus` (port form) fills it with `get_class($event)`.
- **Request.** pdo `FactClassRecordingEventBus` and sf's CR sf-1 decorator can now read `$record->event_class` and become pass-throughs (owners' choice). `OperatorItem::$detail` (CR-PC-4, optional) is **not** added: it would change `toArray()`'s shape, and no caller needs it yet.

## Behaviour changes (what an existing caller can observe)

1. With no injected policy and none in `HostDefaults`, the act bracket now honours `#[Audit(false)]` / `#[Audit(parameters: false)]`. Commands without the attribute are audited exactly as before.
2. Audit parameters mask more keys: any key containing `password`/`passwd`/`secret`, ending in `token`, or one of `private_key`, `secret_key`, `apikey`, `pem`, `credentials`. PEM values are redacted under any key. Binary strings are summarised without content (before, they reached the sink raw and could make its JSON encoding fail).
3. The port-form outbox bus refuses, at append, a payload that is not JSON-encodable (`UnencodablePayload`) or is over 8 MiB encoded (`PayloadTooLarge`). Before, the first was store-dependent and the second was unbounded. The 0.6 repository form is unchanged.
4. A D1 failure command dispatched by the delivery invoker has a deterministic command id.
5. With `InMemoryOutboxStore` (and any store adopting `IReportsClaimDeadLetters`), a row whose submitter keeps dying is dead-lettered after `max_attempts` re-claims instead of being re-claimed forever.

## Not done here (other owners, or out of these paths)

- D1 inside a process step ("perform retries follow the step's retry policy", register 3.8 / 5.1) lives in `Application/Process/**`, which this author does not own. With `EffectMiddleware` on the bus, a process-level retry of the step already reuses the journal, because the step re-dispatches the command and the middleware finds the entry.
- Host wiring of `EffectMiddleware` (sf `services.php` between `act_bracket` and `transaction`, the pdo `DurableRuntime` bus, the wp container) and the effect journals (sf DBAL journal, pdo) belong to their owners.
- L8 (sf) wants "the library's conflict exception". Core ships no `ConflictException` this round. If sf wants a core type rather than an sf one, the additive core fix is `Domain\Exceptions\ConflictException extends BusinessConstraintException`.
