# Wave 0 / A: core boundary (ddd-src)

**Role:** A-core-boundary. Read-only investigation.
**Source:** `/Users/titustc/tgbl/repos/tangible-ddd`, branch `extraction/ddd-packages`, HEAD `598858c` (0.6.6).
**Scope:** `ddd-src/Domain`, CQRS (command/query bus and middleware), eventing, correlation/trace, consumer registry, `composer.json`. The file map covers all 166 PHP files under `ddd-src`. `ddd-wordpress` (40 PHP files) is out of scope except where a `ddd-src` file calls into it.
**Operator decisions applied:** core ships no bundled MySQL runtime, worker daemon or migration framework. The plain-PHP / CodeIgniter host passes in its own connection (for example a PDO, or a thin adapter over whatever `$db` it already has). Core offers ports, in-memory doubles, at most a thin PDO adapter plus schema SQL, and a `runOnce()`/drain entry point that the host schedules. That supersedes spec M3a and review finding 4. The owner tag `pdo-default` below means that thin adapter and nothing larger.

All `path:line` citations point into `/Users/titustc/tgbl/repos/tangible-ddd` unless stated otherwise.

---

## 0. How this was verified

Every claim was checked against code. Wherever possible it was also checked by running the code.

| Probe | Command / artifact | Result |
|---|---|---|
| Unit baseline (with WP stubs) | `vendor/bin/phpunit` (bootstrap `tests/bootstrap.php`, which loads `tests/wp-stubs.php`) | 628 tests. This is the orchestrator's stated baseline and was not re-run here. |
| **Unit suite with NO WP stubs** | `phpunit --no-configuration --bootstrap <scratch>/nostub-bootstrap.php tests/Unit`. The bootstrap only defines `DOING_TANGIBLE_TESTS` and requires `vendor/autoload.php`. | **628 tests, 186 errors.** The top missing symbols are class `wpdb` (97), `add_action()` (26), `is_multisite()` (14), `$wpdb->get_var` on null (9), `register_rest_route()` (4) and `wp_json_encode()` (3). Errors by area: WordPress 60, Process 44, Correlation 23, Workflow 13, Outbox 10, Dashboard 10, Consumers 9, Persistence 5, Events 4, EventHandlers 4. `tests/Unit/Domain` (5) and `tests/Unit/CQRS` (38) are fully green without stubs. |
| Zero-WP vertical probe | scratchpad `probe/run.php` + `body.php`, `probe/run2.php` + `body2.php` (outside the repo). They compose a Tactician bus by hand over a 10-line PSR-11 map container. | See §4. The core command → domain event → integration record path **works with zero WP loaded** once four classes are left out. Those four fail with WP symbol errors. |

The "628 green" baseline therefore does **not** show that anything is WordPress-free. `tests/wp-stubs.php` (313 lines) stubs `wpdb`, `WP_Error`, `WP_REST_Request`, hooks, options, `wp_json_encode`, `is_multisite` and Action Scheduler functions.

---

## 1. Headline

1. The portable core already exists inside `ddd-src`. On the command path, WP is tangled in at **four classes plus two decoration calls** (touches indexing, infrastructure signals), not spread throughout. `EventsUnitOfWork`, `EventRouter`, `DomainEventsPublishMiddleware`, `SelfExecutingCommandMiddleware`, `ConsumerRegistry`, `Correlation`/`TraceContext`, `SelfHandlingCommand`/`SelfHandlingQuery`, `DomainEvent`/`IntegrationEvent` and `Aggregate` ran with zero WP symbols defined. They returned the command result, dispatched the domain event and staged the integration record (probe `core`, `query`, `plain_handler`, `outbox_plain`).
2. **Four classes on the default pipeline cannot run without WP.** `CorrelationMiddleware` fatals in `ddd-wordpress/audit.php:21`. `TransactionMiddleware` throws a TypeError in its constructor at `TransactionMiddleware.php:25`. `WordPressEventDispatcher` calls an undefined `do_action_ref_array`. `Command` (the base class) calls the undefined `SelfConsumer\di()` at `Command.php:25`. Each needs a port plus a core default. None needs a rewrite.
3. **`composer require` alone already runs WordPress boot code.** Composer `autoload.files` → `tangible-ddd.php`. Outside WP that file calls `initialize_latest()` straight away (`tangible-ddd.php:317-319`). The result: the global `Tangible_DDD_Versions` class and the `TANGIBLE_DDD_VERSION` constant exist, a **prepended** SPL autoloader is registered, and 55 `TangibleDDD\WordPress\*` functions are defined (probe output). This breaks spec contract 5 and has to be the first packaging change.
4. The Domain layer is not self-contained. `Event::prefix()` reads `ConsumerRegistry` (`Domain/Events/Event.php:26`). `JsonLifecycleValue` resolves its renderer through the registry (`Domain/Shared/JsonLifecycleValue.php:47-48`). `TangibleFieldsRenderer` calls `tangible_fields()` (`Domain/DataRendering/TangibleFieldsRenderer.php:21,31`). The first is acceptable inside ddd-core. The last one moves to wp.
5. `composer.json` requires four packages that core must not carry: `symfony/dependency-injection`, `symfony/config`, `woocommerce/action-scheduler` and `makinacorpus/query-builder`. It also **omits** `psr/container`, which ddd-src imports directly (4 files) and which currently arrives only through Symfony DI. Tactician is pinned to `^2.0-rc1`, and ddd-core will be a *non-root* dependency. That combination is an install risk (F-24).

---

## 2. Findings

Owner tags: `core` / `wp` / `symfony` / `pdo-default` / `packaging` / `legacy`. Verdicts: **C** = confirmed by running code, **R** = confirmed by reading code, **P** = plausible, needs the named test.

| # | Claim | Evidence (path:line) | Proposed owner | Compatibility risk | Test that proves it | Unresolved question |
|---|---|---|---|---|---|---|
| F-01 (C) | A bare `require vendor/autoload.php` outside WP runs the version-negotiation loader, prepends an autoloader and defines 55 WP-namespaced functions plus a global class and constant. | `composer.json` autoload.files `tangible-ddd.php`; `tangible-ddd.php:140-143,169-175,317-319` (immediate init when `add_action` is absent); `tangible-ddd.php:274-296` (prepend autoloader), `:306-334` (procedural list). Probe: `TANGIBLE_DDD_VERSION=0.6.6 Tangible_DDD_Versions=yes`, 55 functions, 4 autoloaders. | packaging (core autoload is PSR-4 plus `assert.php` only; `tangible-ddd.php` stays in the wp / legacy distribution) | High for WP: every consumer's vendor copy relies on this file running from Composer autoload. It must stay in the legacy `tangible/ddd` distribution unchanged. | Clean Composer project requiring only ddd-core: assert `!class_exists('Tangible_DDD_Versions', false)`, `!defined('TANGIBLE_DDD_VERSION')`, `count(spl_autoload_functions())` equal to Composer's own count, and no `tangibleddd\wordpress\*` in `get_defined_functions()['user']`. | Is `tangible/ddd` rebuilt as ddd-core + ddd-wp + loader, or kept as a separate root distribution (review finding 5)? |
| F-02 (C) | `CorrelationMiddleware` always calls the WP audit functions, so the act bracket (no-nesting guard, scope, audit) cannot run without WP even when audit is unwanted. | `Application/Correlation/CorrelationMiddleware.php:13-15` (`use function TangibleDDD\WordPress\command_audit_*`), `:50` `command_audit_enabled()` → `ddd-wordpress/audit.php:18-21` (`global $wpdb; $wpdb->get_var('SHOW TABLES LIKE')`). Probe `correlation`: `Error: Call to a member function get_var() on null @ ddd-wordpress/audit.php:21`. | core (middleware + `IAuditSink`/`NullAuditSink` + `AuditPolicy`); wp (`WpdbAuditSink` wrapping `audit.php`) | Medium. The DI yaml autowires the constructor (`ddd-wordpress/di/services.yaml:31`). A new optional constructor argument with a WP default injected by the WP container stays compatible. The guard must stay unconditional, as today (`:42-47` sits before the audit check). | Core unit test without stubs: dispatch a command inside a command → `CommandDispatchedInsideCommand` with `NullAuditSink`. Also a WP test where audit is on and rows are written as today. | Should the audit sink be per consumer (as the `static $enabled[$prefix]` cache is today) or per bus? |
| F-03 (R) | `CorrelationMiddleware` also embeds WP actor, multisite and environment lookups. | `CorrelationMiddleware.php:65` (`is_multisite()`, `get_current_blog_id()`), `:69` (`get_bloginfo`), `:121-129` `resolve_source()` (`DOING_CRON`, `get_current_user_id()`). | core `IActorProvider` (D5) with a default that returns cli/system; wp `WpActorProvider`; symfony security-token provider. `blog_id` and the `wp` version move into the WP audit sink. | Low. Audit row columns are unchanged. `source` is still `cli` / `system` / `user`. D5 needs a new `machine` kind; that change is additive. | Core test: the actor provider's output appears in the audit sink payload. WP test: logged-in user → `source=user,id=N`. | D5 needs machine actors (runner hostname, webhook). Is the actor a `{type,id}` pair, or a value object with a `kind` enum? |
| F-04 (C) | `TransactionMiddleware` is bound to `wpdb` by type and reads `$GLOBALS['wpdb']`. Outside WP the constructor throws. In WP, when no connection exists, it silently runs without a transaction. | `Application/Persistence/TransactionMiddleware.php:8,22,24-25` (`private wpdb $wpdb; $this->wpdb = $wpdb ?: $GLOBALS['wpdb']`), `:34-36` (silent fallback). Probe `transaction`: `TypeError: Cannot assign null to property ...$wpdb of type wpdb`. | core (middleware + `ITransactionBoundary` port: begin / commit / rollback, or `run(callable)`); wp (`WpdbTransactionBoundary`, with `query()` return values checked); pdo-default (`PdoTransactionBoundary(\PDO $hostPdo)`); symfony (`DbalTransactionBoundary`) | Medium. The class name and its position in `tactician.yaml:33` stay. The constructor signature changes from `?wpdb` to the port, and the WP DI yaml injects the WP boundary. Any consumer that builds it with `new TransactionMiddleware($wpdb)` needs a WP-side factory or a subclass kept under the old name. | Core test: an `ITransactionalCommand` with no boundary configured throws **before** the handler runs (spec M1 gate). A PDO test on MySQL 8: handler throws → rollback, no rows. | Caller-owned transaction: `PDO::beginTransaction()` throws if one is already open. Reject, or join with a savepoint? (Spec contract 1 leaves this open.) CodeIgniter's `$this->db` is mysqli by default, not PDO. Does core ship only the port and let CI implement it in about 10 lines? |
| F-05 (C) | `WordPressEventDispatcher` is the only `IDomainEventDispatcher` implementation. It dispatches by WP hook name, so domain reactions need `do_action_ref_array`. | `Infra/Services/WordPressEventDispatcher.php:27-32`; alias `ddd-wordpress/di/services.yaml:125-126`. Probe `wpdispatcher`: `Call to undefined function ...do_action_ref_array()`. | wp (move as is); core adds `OrderedListenerDispatcher`, a synchronous, ordered callable map keyed by class **and** by `instanceof` marker interfaces (D2). It must open and close the `Reactions` frame the way `WordPressEventDispatcher.php:27,31` does. | Low if the class keeps its FQCN in wp. Semantic difference: WP handlers *rebuild* the event from args (`WordPressActionHandler.php:22-33,50`), while the core dispatcher passes the published instance. `Reactions` attribution has to use the frame, not the identity of a rebuilt instance. | Core test: two listeners in registration order. A throwing listener propagates and rolls back. `Reactions::of($event)` lists both, with duration. D2: a listener on marker `IRequestsNotification` receives facts of two unrelated classes. | PSR-14 `ListenerProviderInterface` as the core default, or a private map? (The spec allows PSR-14 for this seam.) |
| F-06 (C) | The base `Command` resolves its container through the WP self-consumer function `di()`, which only exists after `ddd-wordpress/self/index.php` is required at `plugins_loaded` priority 20. | `Application/Commands/Command.php:10,24-26`; `ddd-wordpress/self/index.php:25-40`; `tangible-ddd.php` self-consume hook. Probe `plaincommand`: `Call to undefined function TangibleDDD\WordPress\SelfConsumer\di()`. In this repo it is extended only by the 4 self-consumer ops commands (`Application/Commands/{Replay,Purge,Discard,Retry}*Command.php`). | legacy/wp: move this override into a WP-owned base, for example `TangibleDDD\WordPress\SelfConsumer\Command`, or register the self-consumer in `ConsumerRegistry` and delete the override. core: `Command` uses `CommandBusAware` unchanged. | Medium. The FQCN `TangibleDDD\Application\Commands\Command` may be extended by external consumers. Not verified; see open questions. | grep across the five consumer repos (review finding 1) for `extends Command` / `Commands\Command`. Core test: a subclass of `Command` owned by a registered consumer sends through that consumer's bus. | Does the self-consumer ever call `ConsumerRegistry::add()`? No call was found in `ddd-wordpress/self/`. If it did, the override would be redundant. |
| F-07 (C) | `OutboxIntegrationEventBus::publish()` hard-calls the WP touches indexer. A fact that carries `#[Touches]` throws outside WP, despite the code comment "Never throws (decoration)". | `Infra/Services/OutboxIntegrationEventBus.php:64-72` → `ddd-wordpress/touches.php:15-35,50-58` (`global $wpdb`, `is_multisite()`, a `MAX(version)+1` loop). Probe `outbox_touches`: `Call to undefined function TangibleDDD\WordPress\is_multisite() @ ddd-wordpress/touches.php:54`. Probe `outbox_plain` (no `#[Touches]`): OK, because `Footprint` returns `[]` first. | core (`IFactObserver` port, `NullFactObserver`, and calls wrapped in try/catch so decoration can never fail publication); wp (`WpdbTouchesObserver`); symfony/pdo-default optional. | Low. The same data reaches the same table in WP. | Core test: a throwing observer does not stop `outbox->write` and does not propagate. WP test: touches rows as today. | Is touches indexing part of the TXP audit story, or WP-only? (D-map does not ask for it.) |
| F-08 (R) | `InfrastructureEvent::dispatch()` fires WP actions and becomes a **silent no-op** without WP, so DLQ / process-failed / workflow-failed / delivered-unheard signals disappear in a non-WP host. | `Application/Infrastructure/InfrastructureEvent.php:46-53` (`function_exists('do_action')` guard; two `do_action`s, one of them the global `tangible_ddd_` hook). Callers: `OutboxProcessor.php:92` (FactDeliveredUnheard) and others. | core (`IInfrastructureSignalDispatcher` port with a PSR-3 logging default, never silent); wp (a facade that fires both existing hook names). | Low. Hook names are kept inside the WP adapter. | Core test: dead-lettering produces an `OutboxDeadLettered` on the injected dispatcher. WP test: `{prefix}_outbox_dlq` and `tangible_ddd_outbox_dlq` both fire. | The unified operator view (D9) consumes these signals. Does that make the dispatcher a core concern or a symfony one? |
| F-09 (C) | `IntegrationListener`'s constructor registers a WP hook, so a named listener cannot even be constructed outside WP. The decode / trace / translate / send logic lives in a WP procedural file. | `Application/EventHandlers/IntegrationListener.php:26-31` → `ddd-wordpress/integration-events.php:77-104` (`add_action`, `IntegrationEnvelope::unwrap`, `for_fact`, `Correlation::within`, `$command?->send()`). Probe `listener`: `Call to undefined function TangibleDDD\WordPress\add_action()`. | split: core `IntegrationDelivery` / invoker (unwrap → trace ctx → `from_payload` → translate → send), a pure function of `(event_class, wrapped array, translator)`; wp registrar (`add_action` at priority 10, as today); symfony Messenger handler; pdo-default `runOnce()` drain calls the invoker directly. | Medium. `IntegrationListener`'s constructor side effect is public behaviour (DI-constructed listeners register themselves when built). In WP the base class must keep registering on construction. Core needs a variant with no side effects, or a registrar that constructs listeners and wires them. | Core test: the invoker, given an envelope `{__correlation_id,__sequence,__event_id}`, runs the translated command inside a `Kind::Fact` cause whose id is the envelope event_id (this is D13). `IntegrationConformance::listener_violations` stays green. | Should the core listener base drop the constructor side effect, splitting into `IntegrationListener` (wp, legacy) and a core `IIntegrationTranslator`? |
| F-10 (C) | `DDDConfig::table()` and `Config` read `$wpdb->prefix`. Outside WP, `DDDConfig::table()` returns a **wrong name silently** (it drops the prefix, with only a warning). | `Infra/DDDConfig.php:39-42`; `Infra/Config.php:17,21-24,30-31`; `Application/Support/ConsumerTables.php:24-25`. Probe `ddd_config`: `Warning: Attempt to read property "prefix" on null` → `x_outbox`. | split: core `IConsumerIdentity` (prefix, namespace_root, version); `IDDDConfig` keeps its current methods and moves to wp as the WP-extended interface (table / hook / as_group / option). `DDDConfig`, `Config` and `ConsumerTables` move to wp. pdo-default / symfony take a table-name prefix as an explicit constructor value. | **High.** `IDDDConfig` is implemented by every consumer's Config class, is type-hinted all over ddd-src and ddd-wordpress, and is the `ConsumerRegistry::add()` parameter type. Its FQCN `TangibleDDD\Infra\IDDDConfig` must keep resolving. Option: core defines `IConsumerIdentity`, and `IDDDConfig extends IConsumerIdentity` stays at the same FQCN, owned by exactly one package. | Static check that core code only calls `prefix()` / `version()` / `namespace_root()` on its config. Existing consumer Config classes compile unchanged against the split. | Which package owns the FQCN `TangibleDDD\Infra\IDDDConfig`? It sits in core's namespace but carries WP semantics. The spec says an old class name has exactly one owner. Recommendation: core, with WP methods kept on it for the compatibility window; decide at M1. |
| F-11 (R) | `ConsumerRegistry` and `ConsumerHandle` contain no WP code. They are process-static, longest-prefix namespace routing, and ready for core as they are. | `Infra/Consumers/ConsumerRegistry.php:22-251` (no WP symbols; `reset()` at `:185`); `ConsumerHandle.php:18-111`. Probe: `ConsumerRegistry::add(...)` + `owner_of()` worked with zero WP. | core | Low. The WP-era convention of stripping `\Infra` from the namespace (`ConsumerHandle.php:53-64`) is kept for compatibility. | Existing `tests/Unit/Consumers` without stubs: 10 of 19 pass. The 9 errors are all `Class "wpdb" not found` from module tests that build WP containers, so those tests move to wp. | `ConsumerHandle::container()` returns `object` (`:108`), while `CommandBusAware::container()` declares `ContainerInterface` (`CommandBusAware.php:30`). Should core make PSR-11 the declared contract? |
| F-12 (R) | The message identity and the WP hook name are one string. `DomainEvent::action()` = `{prefix}_domain_{name}`. `integration_action()` = `{prefix}_integration_{name}`. The integration string is **persisted** in outbox rows and used as the Action Scheduler hook. | `Domain/Events/DomainEvent.php:17-19`; `Domain/Events/IntegrationBehaviour.php:25-27`; `Infra/Persistence/OutboxRepository.php:43` (column `integration_action`); `Infra/Services/ActionSchedulerOutboxPublisher.php:22-32`. | core (keep both strings exactly; treat `integration_action()` as a stable routing key, not a WP concept) | High if renamed. Stored rows and in-flight AS jobs carry the string. | Golden test: `ThingRenamedFact::integration_action() === 'probe_integration_thing_renamed_fact'` (probe output). Old outbox row fixtures still decode. | Spec contract 4 wants logical identity separated from hook naming. Is that deferred past extraction? Recommendation: yes. No renames. |
| F-13 (C) | Return values pass through the whole pipeline (D11 is already satisfied). | `CorrelationMiddleware.php:80-83` returns `Correlation::within(...)`; `TransactionMiddleware.php:41-45`; `DomainEventsPublishMiddleware.php:17,33`; `SelfExecutingCommandMiddleware.php:93`; Tactician `CommandHandlerMiddleware::execute`. Probe: command `'ok'`, plain handler `'handled'`, query `42`. | core | None. The docblock "receipt rule" in `SelfHandlingCommand.php:38-41` says nothing downstream may *depend* on the return value. D11 does depend on it (checkout URL, AcquireJob wire). | Core test: an `ITransactionalCommand` returns its value through all four middlewares with a `PdoTransactionBoundary`. | Relax the receipt-rule docblock for D11, or does TXP return URLs through a query? |
| F-14 (R) | D13 (cause event_id inside a listener) is reachable today through the static facade, but not as an explicit API. | `ddd-wordpress/integration-events.php:91-94` (`$ctx->for_fact($envelope->event_id, ...)`) → inside the translator, `Correlation::current()->cause->id` is the envelope event_id and `cause->kind === Kind::Fact` (`Application/Correlation/TraceContext.php:34-36`). | core: document `Correlation::peek()?->cause` as the supported read, or pass the envelope to the translator. Add `Uuid::v5()` next to `Uuid::v4()` (`Domain/Shared/Uuid.php:14-23`). | Low (additive). | Core invoker test (F-09) asserting `cause->id`. `Uuid::v5` test against RFC 4122 vectors. | Should the `IIntegrationTranslator` signature receive `(event, FactContext)`? |
| F-15 (R) | The audit redactor is a `final` class with a fixed key list and no extension point (D8). | `Application/Logging/Redactor.php:8-11` (final; `is_sensitive_key` fixed). Used by `CorrelationMiddleware.php:54`. | core (constructor-injected extra key list / predicate, plus a per-command opt-out of parameter capture) | Low (additive constructor argument with a default). | Core test: a custom key `pem` is masked. A binary body over 1024 bytes is summarised (`Redactor.php:19-21`). | D8: can IngestTrace skip parameter capture entirely (D12 audit policy) rather than redact? |
| F-16 (R) | There is no per-command audit policy (D12). Audit is on or off per consumer, based on whether the table exists. | `ddd-wordpress/audit.php:10-25` (static per-prefix cache, `SHOW TABLES LIKE`); `CorrelationMiddleware.php:50`. | core `AuditPolicy` (for example an `#[Audit(false)]` attribute or `IUnaudited` marker) consulted before the sink; the guard stays unconditional. | Low. | Core test: an unaudited command writes no sink rows but still trips `CommandDispatchedInsideCommand` when nested. | Attribute or interface? Attribute matches the existing `#[Touches]` / `#[StartsOn]` style. |
| F-17 (R) | The domain-event buffer is reset at the *start* of each command, not in `finally`. Failed commands leave queued events until the next command. The act bracket reads `published()` in its `finally`. | `Application/Events/DomainEventsPublishMiddleware.php:15-18`; `EventsUnitOfWork.php:31-35`; `CorrelationMiddleware.php:100-103`. | core (add `finally { reset-on-failure }` or document why not) | Low. A behaviour change on failure paths only. | Worker test (spec scenario "Two messages in one worker, first fails"): the second message's audit `events` excludes the first message's queue. | Was start-only reset intentional? (The audit of a failed command reads its partial drains.) |
| F-18 (R) | Process-static runtime state has to be reset between messages in long-lived workers. Only some of it has reset hooks. | `Correlation.php:23-28,64-68` (`reset`); `Reactions.php:24,27,70-73` (`reset`); `PublishedFacts.php:21` (WeakMap, no reset: acceptable); `IntegrationHookName.php:29,58-60` (`reset`); `ConsumerRegistry.php:25,28` (boot-time; must **not** reset per message); `JsonLifecycleValue.php:26` (static renderer). | core (a `RuntimeReset::between_messages()` that clears Correlation + Reactions and leaves the registry alone); symfony (`kernel.reset` / Messenger `WorkerMessageHandledEvent` hook); pdo-default (`runOnce()` calls it in `finally`). | Low. | Worker test: message 1 throws inside `Correlation::within` → message 2 sees `Correlation::peek() === null`. | None. |
| F-19 (R) | `WordPressActionHandler` registers `add_action` in its constructor and rebuilds events from hook args. WP-only by construction. | `Application/EventHandlers/WordPressActionHandler.php:44-65` (add_action at `:49`), `:22-33` (reconstruction). | wp (move, same FQCN via one owner) | Medium. It is the base class for consumer domain reactions in WP. | Existing WP unit/integration tests under wp. Core equivalent: `IEventHandler` + `OrderedListenerDispatcher` (F-05). | Does core keep the `IEventHandler` interface (`EventHandlers/IEventHandler.php:7-14`, pure) as the listener contract? Recommendation: yes. |
| F-20 (R) | `WPErrorException` takes `\WP_Error`. | `Application/Exceptions/WPErrorException.php:10-14`. | wp | Low. | WP unit test. | None. |
| F-21 (R) | `TangibleFieldsRenderer` (in **Domain**) calls `tangible_fields()`. | `Domain/DataRendering/TangibleFieldsRenderer.php:20-31`. | wp. `IValueRenderer`, `NullValueRenderer` and `AbstractValueRenderer` stay in core. | Low. | Core suite passes with `tangible_fields` undefined. | None. |
| F-22 (R) | The four outbox repair handlers (self-consumer ops) run raw `$wpdb` SQL, `wp_generate_uuid4()` and `ARRAY_A`. Replay (insert, then delete) is not atomic. | `Application/CommandHandlers/DiscardDeadLetterHandler.php:18-22`; `PurgeOutboxHandler.php:21-30`; `ReplayDeadLetterHandler.php:22-58` (insert `:32`, delete `:58`, no transaction); `RetryDeliveryHandler.php:18-34`; `ConsumerTables.php:24`. | split: commands + orchestration core; SQL behind an `IOutboxAdministration` port (wp / pdo-default / symfony) | Medium. The CLI and dashboard dispatch these commands by FQCN. | Conformance: replay makes exactly one outbox row and removes the DLQ row, or neither (spec scenario). | Owned by role B (outbox)? This report only marks the boundary. |
| F-23 (R) | `OutboxConfig::from_options()` reads WP options, and the value object carries Action Scheduler-specific fields. | `Application/Outbox/OutboxConfig.php:10` (final), `:28-40` (`get_option` × 9, `action_scheduler_group`, `max_action_scheduler_payload_bytes`, `route_large_payloads_to_external`). Probe `outbox_config`: `Call to undefined function ...get_option()`. | split: core immutable retry/batch policy; the `from_options` factory and AS fields move to wp. | Medium. `ddd-wordpress/di/services.yaml:52-55` names `OutboxConfig::from_options` as the DI factory. The WP yaml must point at a WP factory; the old static can remain as a deprecated wp-side shim only if the class keeps one owner. | Core test: the constructor alone builds a valid config. WP test: option values flow through. | The class is `final`. Move the WP factory to a separate `WpOutboxConfigFactory` class? |
| F-24 (P) | `league/tactician` `^2.0-rc1` pulled in *transitively* by ddd-core will fail to resolve for a consumer whose root `minimum-stability` is `stable`. Composer applies stability flags only from the root package. Tactician also leaves out its own `psr/container` requirement. | `composer.json` require `"league/tactician": "^2.0-rc1"`, `"minimum-stability": "stable"`; `composer.lock`: tactician `2.0-rc1`, `require: {php}` only; `vendor/league/tactician/src/Handler/CommandHandlerMiddleware.php:9` imports `Psr\Container\ContainerInterface`. | packaging (core requires `psr/container: ^1.1\|^2.0` explicitly; document that consumers must also require `league/tactician:^2.0-rc1` at root, or pin a stable line) | Medium for TXP (Symfony root is `stable`) and for any new consumer. | Fresh `composer init` project with `minimum-stability: stable` and a path repository to ddd-core: `composer require tangible/ddd-core` must succeed, or fail with a stability error that proves this finding. | Is there any stable Tactician 2.x? If not, does core stay on rc1 (the operator says keep Tactician), with a documented root requirement? |
| F-25 (R) | `psr/container` is imported directly by core code but not declared. | `Application/CQRS/CommandBusAware.php:6`, `QueryBusAware.php:6`, `SelfExecutingCommandMiddleware.php:8`, `Commands/Command.php:8`. Lock: `psr/container 2.0.2` reaches the tree only through `symfony/dependency-injection`. | packaging | Low. | `composer why psr/container` in a core-only install. | None. |
| F-26 (R) | Symfony DI compiler passes live in `ddd-src`. | `Infra/DependencyInjection/DDDCompilerPasses.php:5-6`; `LongProcessCatalogPass.php:6-7`. The only Symfony imports in ddd-src. | symfony (bundle), with a thin re-export in wp, because WP containers also use Symfony DI (`ddd-wordpress/self/index.php:17-19`). | Medium. WP consumers' container builders call `DDDCompilerPasses`. The FQCN must resolve from wp. | WP compiled-container test; Symfony kernel test. | Which package owns them? Both wp and symfony need them, and each FQCN may have only one owner. Options: (a) wp owns them and ddd-symfony depends on ddd-wp, which is undesirable; (b) a tiny shared `ddd-symfony-di` package that both require; (c) core owns them behind `suggest: symfony/dependency-injection`. Decide at M2. |
| F-27 (R) | `makinacorpus/query-builder` is used by exactly one ddd-src file, which no ddd-src or ddd-wordpress code consumes. It exists for consumers. | `Infra/Persistence/Select/QueryBuilderSelect.php:5`; grep finds no internal users. | legacy/wp (move out of core; core drops the dependency) | Medium. Consumers that extend `QueryBuilderSelect` must require the package that owns it. | grep across the five consumers for `QueryBuilderSelect`. | Keep it in wp, or in a tiny optional `ddd-query` package? |
| F-28 (R) | `ddd-wordpress/di/tactician.yaml` ("fully wired") references classes that do not exist in the installed Tactician or in the repo. The existing test only YAML-parses it and never compiles it. | `ddd-wordpress/di/tactician.yaml:87,93` (`...\Mapping\MapByNamingConvention\MapByNamingConvention`; the installed class is `...\Mapping\MapByNamingConvention`), `:99` (`TangibleDDD\WordPress\DI\HandlerClassNameInflector`, no such file). `ddd-wordpress/self/HandlerClassNameInflector.php:10-13` admits the di/ copy is stale. `tests/Unit/CQRS/SelfExecutingMiddlewareWiringTest.php:42` uses only `Yaml::parseFile`. There are three inflector copies (`Application/CQRS/HandlerClassNameInflector.php`, `ddd-wordpress/self/HandlerClassNameInflector.php`, plus the missing di/ one). | legacy/wp (fix the yaml to point at `TangibleDDD\Application\CQRS\HandlerClassNameInflector` and the right mapping class; keep one inflector in core) | Low. It is a template; consumers apparently carry their own copy (not verified). | WP test that **compiles** `di/services.yaml` + `di/tactician.yaml` with a stub `IDDDConfig` and resolves `League\Tactician\CommandBus`. | Is this yaml imported by any live consumer, or only by the scaffolder? |
| F-29 (R) | `ICommand` / `IQuery` require `send()`, so every command is tied to the static registry lookup. | `Application/Commands/ICommand.php:5-7`; `Queries/IQuery.php:5-7`; `CQRS/CommandBusAware.php:30-36` (container via `ConsumerRegistry::owner_of(static::class)`, service id `League\Tactician\CommandBus`); `QueryBusAware.php:26` (magic id `tactician.query_bus`). | core (keep; also document explicit `$bus->handle($cmd)` as the non-registry path). The service ids become core constants. | High if changed. Every consumer command implements `send()` via the trait. | Probe `plain_handler` (explicit bus) and `core` (`->send()` via registry) both passed. | Should core export `CommandBusAware::QUERY_BUS_ID = 'tactician.query_bus'` so Symfony wiring does not hard-code it? |
| F-30 (R) | The Domain layer imports Application/Infra runtime pieces. That is fine inside ddd-core, but the Domain is not a separable package. | `Domain/Events/Event.php:25-27` (`ConsumerRegistry::owner_of`); `Domain/Events/IntegrationBehaviour.php:11` (unused import of `PublishedFacts`); `Domain/Shared/JsonLifecycleValue.php:45-58` (registry renderer lookup, never throws). | core (accept; spec line 137) | None now. | Static dependency check (deptrac or phpstan rule): `Domain\*` may import only `Domain\*`, `Infra\Consumers\*` and `Application\Events\PublishedFacts`. | Is a later pure `ddd-domain` package wanted? Not for this extraction. |
| F-31 (R) | `error_log` is the only logging channel in core-path files. | `Application/Events/Footprint.php:60`; `Infra/Consumers/IntegrationHookName.php:50`; `Application/BehaviourWorkflows/WorkflowHandler.php:127`; `Infra/Services/OutboxProcessor.php:159-165` (also `WP_DEBUG`, `wp_json_encode`). | core (`psr/log` `LoggerInterface`, `NullLogger` or `error_log` default); wp keeps `error_log`. | Low. | Core test with a spy logger for an absent-consumer listener (`IntegrationHookName::note_absent`). | Is PSR-3 a hard or suggested core dependency? Recommendation: hard (it is tiny). |
| F-32 (R) | Every row writer stamps multisite `blog_id` inline, including one core-policy class. | `Application/BehaviourWorkflows/WorkflowHandler.php:209`; `Infra/Persistence/{WorkItem:93,Process:55,BehaviourWorkflow:121,Outbox:57}Repository.php`. | core: remove from `WorkflowHandler` (the repository stamps it). wp repositories keep it. | Low. | `WorkflowHandler` unit test without stubs. | None. |
| F-33 (R, verified bug, other role) | GET_LOCK NULL is cast to `''` and passes the `'0'` check, so the critical section runs unlocked. Locks are named by process id only (no consumer). | `Application/Process/ProcessRunner.php:394-408` (`(string) $acquired === '0'` at `:399`). | core policy + lock port; wp/pdo-default/symfony implementations (role C) | Fix on the extraction branch (operator). | Lock-port conformance: `acquire()` returning an error or null must throw. | Owned by the process role. Recorded here because `ProcessRunner` is on the file map. |
| F-34 (R, verified bug, other role) | Ignition is check-then-insert. | `ProcessRunner.php:165-174` → `Infra/Persistence/ProcessRepository.php:90-100` (`has_ignition`, then a separate insert in `start()` → `save`). | core + repo unique constraint | Fix on branch. | Two concurrent ignitions for the same `(consumer, class, event_id)` → one row. | Role C. |
| F-35 (R, verified bug, other role) | Delayed events are delayed twice. | `Infra/Persistence/OutboxRepository.php:31-32` (`scheduled_at = now + delay`), `:94-99` (fetch gated by `scheduled_at <= now`) → `Infra/Services/ActionSchedulerOutboxPublisher.php:21-27` (`time() + $entry->delay_seconds` again). | wp (publisher schedules at `now` once the row is due) | Fix on branch. | Delayed-event conformance: due once. | Role B. |

---

## 3. File-level map for ddd-src (166 files)

Action: **keep** (moves into ddd-core as it is, same FQCN) / **split** (portable part to core, adapter part out) / **move** (whole file to another package, same FQCN, one owner). "WP / vendor coupling" lists the exact lines that force the action. An empty cell means no WP symbol, global, hook or vendor framework import was found (grep over `$wpdb`, `global`, `wp_*`, hooks, options, `as_*`, `WP_*`, `tangible_*`, `is_multisite`, `DOING_*`, `TangibleDDD\WordPress\*`, plus `use` imports).

### 3.1 Domain (47 files)

| File | Action | Owner | WP / vendor coupling | Note |
|---|---|---|---|---|
| Domain/BehaviourWorkflow.php | keep | core | | Aggregate; behaviour registry via `BaseBehaviourConfig`. |
| Domain/DataRendering/AbstractValueRenderer.php | keep | core | | |
| Domain/DataRendering/NullValueRenderer.php | keep | core | | Core default renderer. |
| Domain/DataRendering/TangibleFieldsRenderer.php | move | wp | `:21` `function_exists('tangible_fields')`, `:31` `tangible_fields()` | F-21 |
| Domain/Events/AlreadyIntegrated.php | keep | core | | Re-raise guard exception. |
| Domain/Events/DomainEvent.php | keep | core | (docblock mentions AS at `:7`) | `action()` string is the stable key (F-12). |
| Domain/Events/Event.php | keep | core | `:26` → `ConsumerRegistry` | F-30 |
| Domain/Events/IAnnouncesIntegration.php | keep | core | | |
| Domain/Events/IDomainEvent.php | keep | core | | |
| Domain/Events/IEventFromArgs.php | keep | core | | Used by the WP handler rebuild; harmless in core. |
| Domain/Events/IIntegrationEvent.php | keep | core | (docblock `:7`) | |
| Domain/Events/IntegrationBehaviour.php | keep | core | `:11` unused import | Scalarise/hydrate codec: the stored-payload contract. |
| Domain/Events/IntegrationEvent.php | keep | core | | |
| Domain/Events/NonReversibleValue.php | keep | core | | |
| Domain/Events/Op.php | keep | core | | |
| Domain/Events/Touches.php | keep | core | | The attribute is portable; its indexer is not (F-07). |
| Domain/Events/TouchesNonAggregate.php | keep | core | | |
| Domain/Exceptions/BusinessConstraintException.php | keep | core | | |
| Domain/Exceptions/InvariantException.php | keep | core | | |
| Domain/Exceptions/RefNotFoundException.php | keep | core | | |
| Domain/Exceptions/TypeMismatchException.php | keep | core | | |
| Domain/Exceptions/WorkflowException.php | keep | core | | |
| Domain/Repositories/IBehaviourWorkflowRepository.php | keep | core | | Port. |
| Domain/Repositories/IWorkItemRepository.php | keep | core | | Port. |
| Domain/Services/IDomainService.php | keep | core | | |
| Domain/Shared/Aggregate.php | keep | core | | |
| Domain/Shared/DirectJsonLifecycleValue.php | keep | core | | |
| Domain/Shared/Entity.php | keep | core | | |
| Domain/Shared/IDTOConstructible.php | keep | core | | |
| Domain/Shared/IJsonSerializable.php | keep | core | | |
| Domain/Shared/IRecordsDomainEvents.php | keep | core | | |
| Domain/Shared/IValueObject.php | keep | core | | |
| Domain/Shared/IValueRenderer.php | keep | core | | |
| Domain/Shared/JsonLifecycleValue.php | keep | core | `:26` static renderer, `:47-48` registry lookup | Stored-process codec (`serialize_polymorphic`). |
| Domain/Shared/RecordsDomainEvents.php | keep | core | | |
| Domain/Shared/Uuid.php | keep | core | | Add `v5()` (D13, F-14). |
| Domain/Shared/ValueObject.php | keep | core | | |
| Domain/Shared/assert.php | keep | core (packaging) | loaded by the loader list `tangible-ddd.php:308`, not by Composer | Core `autoload.files`: this one file only. |
| Domain/ValueObjects/Behaviours/BaseBehaviourConfig.php | keep | core | `:29` static type registry | Boot-time registry; do not reset per message. |
| Domain/ValueObjects/Behaviours/BatchableBehaviourConfig.php | keep | core | | |
| Domain/ValueObjects/Behaviours/BehaviourExecutionResult.php | keep | core | | |
| Domain/ValueObjects/Behaviours/BehaviourExecutionStatus.php | keep | core | | |
| Domain/ValueObjects/Behaviours/ISagaBehaviour.php | keep | core | | |
| Domain/ValueObjects/Behaviours/WorkItem.php | keep | core | | |
| Domain/ValueObjects/Behaviours/WorkItemList.php | keep | core | | |
| Domain/ValueObjects/Behaviours/WorkItemStatus.php | keep | core | | |
| Domain/ValueObjects/EntityAttributes/BaseAssociatedEntityAttributes.php | keep | core | | |

### 3.2 Application: CQRS, commands, queries (20 files)

| File | Action | Owner | WP / vendor coupling | Note |
|---|---|---|---|---|
| Application/CQRS/CommandBusAware.php | keep | core | Tactician `CommandBus`, PSR-11 | F-29 |
| Application/CQRS/HandlerClassNameInflector.php | keep | core | Tactician `ClassNameInflector` | The single surviving inflector (F-28). |
| Application/CQRS/QueryBusAware.php | keep | core | `:26` magic id `tactician.query_bus` | F-29 |
| Application/CQRS/SelfExecutingCommandMiddleware.php | keep | core | Tactician `Middleware`, PSR-11 | Requires the container to return the **same** `EventsUnitOfWork` instance as `DomainEventsPublishMiddleware` (`:80-85`). |
| Application/Commands/Command.php | split | core (base) + legacy/wp (self-consumer container) | `:10,25` `TangibleDDD\WordPress\SelfConsumer\di()` | F-06 |
| Application/Commands/ICommand.php | keep | core | | |
| Application/Commands/ITransactionalCommand.php | keep | core | | Marker read by the transaction middleware. |
| Application/Commands/SelfHandlingCommand.php | keep | core | | |
| Application/Commands/DiscardDeadLetterCommand.php | keep | core | | Self-consumer op; extends `Command` (F-06). |
| Application/Commands/PurgeOutboxCommand.php | keep | core | | as above |
| Application/Commands/ReplayDeadLetterCommand.php | keep | core | | as above |
| Application/Commands/RetryDeliveryCommand.php | keep | core | | as above |
| Application/CommandHandlers/ICommandHandler.php | keep | core | | |
| Application/CommandHandlers/DiscardDeadLetterHandler.php | split | core + wp/pdo-default/symfony (`IOutboxAdministration`) | `:18-22` `$wpdb->delete`, `last_error` | F-22 |
| Application/CommandHandlers/PurgeOutboxHandler.php | split | as above | `:21-30` `$wpdb->query/prepare` | F-22 |
| Application/CommandHandlers/ReplayDeadLetterHandler.php | split | as above | `:22-58` `$wpdb`, `ARRAY_A`, `wp_generate_uuid4()` | F-22; not atomic |
| Application/CommandHandlers/RetryDeliveryHandler.php | split | as above | `:18-34` `$wpdb->update` | F-22 |
| Application/Queries/IQuery.php | keep | core | | |
| Application/Queries/SelfHandlingQuery.php | keep | core | | |
| Application/QueryHandlers/IQueryHandler.php | keep | core | | |

### 3.3 Application: eventing, correlation, handlers, exceptions, logging, tracing (27 files)

| File | Action | Owner | WP / vendor coupling | Note |
|---|---|---|---|---|
| Application/Correlation/Cause.php | keep | core | | |
| Application/Correlation/Correlation.php | keep | core | static state `:23-28` | F-18; the single worker-mode seam. |
| Application/Correlation/CorrelationMiddleware.php | split | core (bracket + ports) + wp (audit sink, actor provider) | `:13-15,50,57,95` audit functions; `:65` multisite; `:69` `get_bloginfo`; `:124` `DOING_CRON`; `:127` `get_current_user_id` | F-02, F-03, F-16 |
| Application/Correlation/Kind.php | keep | core | | |
| Application/Correlation/TraceContext.php | keep | core | | |
| Application/Events/DomainEventsPublishMiddleware.php | keep | core | Tactician `Middleware` | F-17 |
| Application/Events/EventRouter.php | keep | core | | |
| Application/Events/EventsUnitOfWork.php | keep | core | | Seal and re-raise guards. |
| Application/Events/Footprint.php | keep | core | `:41` registry, `:60` `error_log` | F-31 |
| Application/Events/IDomainEventDispatcher.php | keep | core | (docblock `:10` says `do_action`) | Port. Core default added (F-05). |
| Application/Events/IIntegrationEventBus.php | keep | core | | Port. |
| Application/Events/IntegrationEnvelope.php | keep | core | | Envelope keys `__correlation_id/__sequence/__event_id` are wire-stable. |
| Application/Events/PublishedFacts.php | keep | core | WeakMap | |
| Application/Events/RaisesEvents.php | keep | core | `:32-33` message mentions `tangible.events_unit_of_work` | Wording only. |
| Application/Events/Reactions.php | keep | core | static state | F-18 |
| Application/EventHandlers/IEventHandler.php | keep | core | | Core listener contract. |
| Application/EventHandlers/IntegrationListener.php | split | core (translator/invoker) + wp (constructor-registering base) | `:27` `\TangibleDDD\WordPress\integration_listener()` | F-09 |
| Application/EventHandlers/WordPressActionHandler.php | move | wp | `:49` `add_action` | F-19 |
| Application/Exceptions/ApplicationException.php | keep | core | | |
| Application/Exceptions/CommandDispatchedInsideCommand.php | keep | core | | |
| Application/Exceptions/DomainEventAfterSealException.php | keep | core | | |
| Application/Exceptions/SelfHandlingCommandHasNoHandler.php | keep | core | | |
| Application/Exceptions/SelfHandlingCommandWrapsHandler.php | keep | core | | |
| Application/Exceptions/UnresolvableHandleDependency.php | keep | core | | |
| Application/Exceptions/WPErrorException.php | move | wp | `:10,14` `\WP_Error` | F-20 |
| Application/Logging/Redactor.php | keep | core | final, fixed keys | F-15 (D8) |
| Application/Tracing/TraceStitcher.php | keep | core | | Read-side; used by the WP dashboard (`ddd-wordpress/Admin/Dashboard/Query/UnifiedTraceQuery.php`). |

### 3.4 Application: infrastructure signals, outbox, persistence, support, DTOs (18 files)

| File | Action | Owner | WP / vendor coupling | Note |
|---|---|---|---|---|
| Application/Infrastructure/IInfrastructureEvent.php | keep | core | (docblock `:21` hook names) | |
| Application/Infrastructure/InfrastructureEvent.php | split | core (message) + wp (hook facade) | `:47-52` `function_exists('do_action')`, `do_action` ×2 | F-08 |
| Application/Infrastructure/FactDeliveredUnheard.php | keep | core | | Its trigger (`has_action`) is WP-only (see OutboxProcessor). |
| Application/Infrastructure/OutboxAttemptFailed.php | keep | core | | |
| Application/Infrastructure/OutboxDeadLettered.php | keep | core | | |
| Application/Infrastructure/ProcessFailed.php | keep | core | | |
| Application/Infrastructure/WorkflowFailed.php | keep | core | | |
| Application/Outbox/IOutboxPublisher.php | keep | core | | Port. |
| Application/Outbox/OutboxConfig.php | split | core (policy) + wp (options factory, AS fields) | `:30-39` `get_option` ×9; AS fields | F-23 |
| Application/Outbox/OutboxEntry.php | keep | core | | |
| Application/Persistence/TransactionMiddleware.php | split | core (middleware + `ITransactionBoundary`) + wp / pdo-default / symfony | `:8,22-25` `wpdb`, `$GLOBALS['wpdb']`; `:34` silent fallback | F-04 |
| Application/Support/ConsumerTables.php | move | wp | `:24-25` `$wpdb->prefix` | F-10, F-22 |
| Application/DataTransferObjects/BaseDTO.php | keep | core | | |
| Application/DataTransferObjects/IDataTransferObject.php | keep | core | | |
| Application/DataTransferObjects/Collections/BaseDTOCollection.php | keep | core | | |
| Application/DataTransferObjects/Collections/IDTOCollection.php | keep | core | | |
| Application/Traits/DTOConstructibleTrait.php | keep | core | | |
| Application/TypedLists/JsonLifecycleValueList.php | keep | core | | |

### 3.5 Application: process and workflow (18 files). Detail belongs to the process role.

| File | Action | Owner | WP / vendor coupling | Note |
|---|---|---|---|---|
| Application/BehaviourWorkflows/IWorkItem.php | keep | core | | |
| Application/BehaviourWorkflows/WorkflowHandler.php | split (small) | core | `:127` `error_log`; `:209` `is_multisite()/get_current_blog_id()` | F-31, F-32; `reschedule()` is already an abstract seam (`:107`). |
| Application/Process/Async.php | keep | core | (docblock `:11`) | |
| Application/Process/AwaitAll.php | keep | core | | |
| Application/Process/AwaitEvent.php | keep | core | | |
| Application/Process/AwaitedEventNotRegistered.php | keep | core | | |
| Application/Process/Awaits.php | keep | core | | |
| Application/Process/Compensates.php | keep | core | | |
| Application/Process/IAwaitMechanism.php | keep | core | | |
| Application/Process/LongProcess.php | keep | core | | Stored-process codec. |
| Application/Process/LongProcessCatalog.php | keep | core | | |
| Application/Process/ProcessRunner.php | split | core (algorithm) + ports: hook registrar, scheduler, lock (wp / pdo-default / symfony) | `:77,148` `add_action`; `:220` `WP_CLI`; `:394-408` `$wpdb` GET_LOCK (F-33); `:649,665` `as_schedule_single_action` / `as_enqueue_async_action` | F-33, F-34 |
| Application/Process/ProcessStartedInsideCommand.php | keep | core | | |
| Application/Process/ProcessStartedInsideProcess.php | keep | core | | |
| Application/Process/ProcessSteps.php | keep | core | | |
| Application/Process/RescheduleAware.php | keep | core | | |
| Application/Process/Result.php | keep | core | | |
| Application/Process/StartsOn.php | keep | core | | |

### 3.6 Infra (35 files)

| File | Action | Owner | WP / vendor coupling | Note |
|---|---|---|---|---|
| Infra/Config.php | move | wp | `:21-24` `global $wpdb`; `:55` `TANGIBLE_DDD_VERSION` | Self-consumer config (F-10). |
| Infra/DDDConfig.php | move | wp | `:40-41` `global $wpdb` | F-10; silent wrong name outside WP. |
| Infra/IDDDConfig.php | split | core (`IConsumerIdentity`) + same-FQCN `IDDDConfig` (single owner, decide at M1) | (docblock `:34` AS) | F-10, high risk |
| Infra/Consumers/ConsumerHandle.php | keep | core | | F-11 |
| Infra/Consumers/ConsumerRegistry.php | keep | core | | F-11 |
| Infra/Consumers/IntegrationHookName.php | keep | core | `:50` `error_log` | F-12, F-31 |
| Infra/Consumers/NoConsumerOwnsClass.php | keep | core | | |
| Infra/DependencyInjection/DDDCompilerPasses.php | move | wp (Symfony DI used by WP containers); symfony bundle calls it | `symfony/dependency-injection` | F-26 |
| Infra/DependencyInjection/LongProcessCatalogPass.php | move | wp / symfony (one owner) | `symfony/dependency-injection` | F-26 |
| Infra/Exceptions/IncorrectUsageException.php | keep | core | | |
| Infra/Exceptions/LockingException.php | keep | core | | |
| Infra/Exceptions/QueryException.php | keep | core | | |
| Infra/IOutboxRepository.php | keep | core | | Port (role B freezes semantics). |
| Infra/IProcessRepository.php | keep | core | | Port. |
| Infra/Persistence/BehaviourWorkflowRepository.php | move | wp | `$wpdb` throughout (`:34-178`), `wp_json_encode` `:156`, multisite `:121` | pdo-default / symfony re-implement the port. |
| Infra/Persistence/OutboxRepository.php | move | wp | `$wpdb` throughout; `get_option` / `update_option` pauses `:344,353,367`; `wp_generate_uuid4` `:431`; multisite `:57` | F-35 |
| Infra/Persistence/ProcessRepository.php | move | wp | `$wpdb` throughout, `wp_json_encode` `:36-49` | F-34 |
| Infra/Persistence/WorkItemRepository.php | move | wp | `$wpdb` `:22-122`, `wp_json_encode` `:91`, multisite `:93` | |
| Infra/Persistence/WordPress/WordPressRepository.php | move | wp | `:46,60,79` post meta; `:122` `\WP_Query` | |
| Infra/Persistence/Select/ISelect.php | move | legacy/wp (with its implementation) | | F-27 |
| Infra/Persistence/Select/QueryBuilderSelect.php | move | legacy/wp | `makinacorpus/query-builder` `:5` | F-27 |
| Infra/Persistence/Shared/IPersistsAggregates.php | keep | core | | |
| Infra/Persistence/Shared/PersistsAggregatesRepository.php | keep | core | | Collects events into the UoW on `save()` (`:55-56`), which is the portable repository base. |
| Infra/Persistence/Shared/ISearchableRepository.php | move | wp | (WP-shaped parameters `only_published`, `per_page=-1`) | Low confidence; open question. |
| Infra/Persistence/Shared/RepositorySearchResult.php | move | wp (with the above) | | |
| Infra/Services/ActionSchedulerOutboxPublisher.php | move | wp | `:22,29` `as_*` | F-35 |
| Infra/Services/FactPublishedInsideProcess.php | keep | core | | |
| Infra/Services/OutboxIntegrationEventBus.php | split (small) | core + `IFactObserver` (wp) | `:70` `\TangibleDDD\WordPress\touches_index_fact()` | F-07 |
| Infra/Services/OutboxProcessor.php | split | core (relay + `runOnce()` drain) + wp (listener presence, logging) | `:69` `has_action`; `:159` `WP_DEBUG`; `:164` `wp_json_encode` | Role B. `process_batch()` is already the portable `runOnce()` shape. |
| Infra/Services/ProcessingResult.php | keep | core | | |
| Infra/Services/RoutingOutboxPublisher.php | move | wp | `:34,58` `apply_filters`; `:43` `wp_json_encode`; typed on `ActionSchedulerOutboxPublisher` `:28` | |
| Infra/Services/WordPressEventDispatcher.php | move | wp | `:29` `do_action_ref_array` | F-05 |
| Infra/Shared/IntList.php | keep | core | | |
| Infra/Shared/StringList.php | keep | core | | |
| Infra/Shared/TypedList.php | keep | core | | |

### 3.7 Testing (1 file)

| File | Action | Owner | WP / vendor coupling | Note |
|---|---|---|---|---|
| Testing/IntegrationConformance.php | keep | core | `:11,118,123` reflects on `IntegrationListener` (class reference only, no call) | Keeps working once `IntegrationListener` has one owner. If the WP listener base moves to wp, the check must target the core translator contract. |

### 3.8 Tally

| Action | Files | Where |
|---|---|---|
| keep (core, unchanged) | 131 | Domain (except the renderer), CQRS, events, correlation types, DTOs, process value types, consumer registry, ports, typed lists, conformance kit. |
| split | 15 | `Command`, 4 repair handlers, `CorrelationMiddleware`, `IntegrationListener`, `InfrastructureEvent`, `OutboxConfig`, `TransactionMiddleware`, `WorkflowHandler` (small), `ProcessRunner`, `IDDDConfig`, `OutboxIntegrationEventBus` (small), `OutboxProcessor`. |
| move to wp / legacy | 20 | `TangibleFieldsRenderer`, `WordPressActionHandler`, `WPErrorException`, `ConsumerTables`, `Config`, `DDDConfig`, 2 DI passes, 4 wpdb repositories, `WordPressRepository`, `ISelect`, `QueryBuilderSelect`, `ISearchableRepository`, `RepositorySearchResult`, `ActionSchedulerOutboxPublisher`, `RoutingOutboxPublisher`, `WordPressEventDispatcher`. |

Total: 166 rows, one per file. A `comm` of `find ddd-src -name '*.php'` against the table rows shows no gaps and no duplicates. Split and move together touch 35 of 166 files (21%).

---

## 4. Minimal portable command / query / domain-event path

### 4.1 What runs today with zero WP loaded (probe evidence)

Composition used by the probe (`scratchpad/probe/body.php`). There was no container framework, no `wp-stubs.php` and no WordPress:

```php
ConsumerRegistry::add($identityConfig, fn () => $psr11Map, 'probe', 'Probe');
$uow = new EventsUnitOfWork();
$bus = new League\Tactician\CommandBus(
    new DomainEventsPublishMiddleware($uow, new EventRouter($localDispatcher, $integrationBus)),
    new SelfExecutingCommandMiddleware($psr11Map),   // container returns the same $uow
);
(new RenameThing())->send();   // SelfHandlingCommand → Aggregate::event() → uow->collect_from()
```

Output: `command result='ok' domain=["probe_domain_thing_renamed"] integration=["probe_integration_thing_renamed_fact"]`. The query bus (`SelfExecutingCommandMiddleware` alone) returned `42`. A plain two-class command through `CommandHandlerMiddleware` + `MapByNamingConvention(new HandlerClassNameInflector(), new Handle())` returned `handled`. `OutboxIntegrationEventBus` with the in-memory `tests/Fakes/FakeOutboxRepository` published a fact that had no `#[Touches]`.

### 4.2 What must move or be ported for the default pipeline to run

The default WP pipeline order (`ddd-wordpress/di/tactician.yaml:29-35`) is Correlation → Transaction → DomainEventsPublish → SelfExecuting → handler. Its core-owned equivalent needs these changes, and only these:

| Blocker | Change | New core port / default | WP adapter | pdo-default (host-supplied connection) | symfony |
|---|---|---|---|---|---|
| `CorrelationMiddleware` → `audit.php` (F-02, F-03, F-16) | Inject the sink, actor and policy. The guard and scope stay as they are. | `IAuditSink` + `NullAuditSink`; `IActorProvider` + `CliOrSystemActorProvider`; `AuditPolicy` | `WpdbAuditSink` (current `audit.php` body, plus multisite and WP version); `WpActorProvider` | optional `PdoAuditSink(\PDO, table)` | DBAL sink; security-token actor |
| `TransactionMiddleware` (F-04) | Inject the boundary. With no boundary, an `ITransactionalCommand` throws before the handler. | `ITransactionBoundary` | `WpdbTransactionBoundary` (checked `query()` results) | `PdoTransactionBoundary(\PDO $hostPdo)`, **the same PDO the host's repositories use** | `DbalTransactionBoundary(Connection)` |
| `WordPressEventDispatcher` (F-05) | Becomes the WP alias only. | `OrderedListenerDispatcher` (class + marker `instanceof`, `Reactions` frame) | existing class | core default | EventDispatcher bridge or core default |
| `Command` base → `di()` (F-06) | Move the override to WP. | none (`CommandBusAware`) | self-consumer base | n/a | n/a |
| `OutboxIntegrationEventBus` → touches (F-07) | Inject the observer; catch its errors. | `IFactObserver` + `NullFactObserver` | touches indexer | none | optional |
| `IntegrationListener` → `add_action` (F-09) | Split the invoker from the registrar. | `IntegrationDelivery::deliver(string $event_class, array $wrapped, callable $translate)` | registrar via `add_action` (current) | called from the host's `runOnce()` drain | Messenger handler |
| `InfrastructureEvent::dispatch` (F-08) | Inject the dispatcher. | `IInfrastructureSignalDispatcher` + PSR-3 logging default | hook facade (both names) | core default | EventDispatcher |
| `error_log` (F-31) | PSR-3. | `LoggerInterface` / `NullLogger` | `error_log` logger | host logger | host logger |
| Config (F-10) | Core reads only identity. | `IConsumerIdentity` | `IDDDConfig` (table/hook/option/as_group) | table prefix passed to the adapter's constructor | container parameter |
| Autoload side effects (F-01) | Core composer `autoload`: PSR-4 + `Domain/Shared/assert.php` only. | n/a | `tangible-ddd.php` in the wp / legacy distribution | n/a | n/a |

What "a `$db` conn somewhere" means for plain PHP / CodeIgniter, per the relayed request: core does **not** discover or own a connection. The host builds a `PdoTransactionBoundary`, and optionally the PDO outbox adapter from role B, from the PDO it already uses for its domain writes. The host then calls `OutboxProcessor::process_batch()` (the existing `runOnce()` shape, `Infra/Services/OutboxProcessor.php:44`) from cron or at the end of the request. A CodeIgniter host on the default mysqli driver implements `ITransactionBoundary` in about 10 lines over `$this->db->trans_begin()/trans_commit()/trans_rollback()` instead of using the PDO adapter. That keeps the domain write and the outbox write on one connection, which is the only thing atomicity needs.

### 4.3 Explicit non-registry path

`$bus->handle($command)` works without `ConsumerRegistry` for plain `ICommand`s (probe `plain_handler`). `->send()` and `Event::prefix()` need a registered consumer that owns the class's namespace, so the registry is a required part of core composition for events. It needs about four lines at bootstrap and no framework.

---

## 5. WordPress-embedding public API inventory (ddd-src)

"Public API" here means anything a consumer extends, calls, type-hints or wires, plus behaviour triggered through such a surface.

| Surface | Kind | Embedded WP | Location | Disposition |
|---|---|---|---|---|
| `IDDDConfig::table()/hook()/as_group()/option()` | interface every consumer implements | table prefix semantics, AS groups, WP options | `Infra/IDDDConfig.php:23-47` | F-10 |
| `DDDConfig`, `Config::for_wordpress()` | concrete config | `global $wpdb` | `Infra/DDDConfig.php:40`, `Infra/Config.php:21-23` | move wp |
| `ConsumerTables::name()` | static helper | `$wpdb->prefix` | `Application/Support/ConsumerTables.php:24` | move wp |
| `Command` (base) | base class | self-consumer `di()` | `Application/Commands/Command.php:25` | F-06 |
| `CorrelationMiddleware` | pipeline class | `command_audit_*`, `is_multisite`, `get_current_blog_id`, `get_bloginfo`, `DOING_CRON`, `get_current_user_id` | `CorrelationMiddleware.php:13-15,50-72,95,124-127` | F-02/F-03 |
| `TransactionMiddleware` | pipeline class | `wpdb` type, `$GLOBALS['wpdb']` | `TransactionMiddleware.php:8,22-25` | F-04 |
| `WordPressEventDispatcher` | default `IDomainEventDispatcher` | `do_action_ref_array` | `WordPressEventDispatcher.php:29` | F-05 |
| `DomainEvent::action()` | hook name used as the dispatch key | `{prefix}_domain_{name}` hook | `DomainEvent.php:17-19` | keep string (F-12) |
| `IntegrationBehaviour::integration_action()` | hook name used as the routing key (persisted) | `{prefix}_integration_{name}` AS / WP hook | `IntegrationBehaviour.php:25-27` | keep string (F-12) |
| `WordPressActionHandler` | base class, constructor side effect | `add_action` | `WordPressActionHandler.php:49` | move wp |
| `IntegrationListener` | base class, constructor side effect | `TangibleDDD\WordPress\integration_listener` → `add_action` | `IntegrationListener.php:27` | F-09 |
| `InfrastructureEvent::dispatch(IDDDConfig)` | signal emission | `do_action` × 2 (`{prefix}_{action}`, `tangible_ddd_{action}`) | `InfrastructureEvent.php:46-53` | F-08 |
| `OutboxIntegrationEventBus::publish()` | default `IIntegrationEventBus` | `touches_index_fact` → `$wpdb`, `is_multisite` | `OutboxIntegrationEventBus.php:70` | F-07 |
| `OutboxConfig::from_options()` | DI factory | `get_option` × 9 | `OutboxConfig.php:28-40` | F-23 |
| `OutboxProcessor::process_batch()` | relay entry point | `has_action`, `WP_DEBUG`, `wp_json_encode` | `OutboxProcessor.php:69,159,164` | role B |
| `RoutingOutboxPublisher` filters | extension hooks | `apply_filters('{prefix}_outbox_transport_for_entry')`, `apply_filters('{prefix}_outbox_publish_external')` | `RoutingOutboxPublisher.php:34,58` | wp (keep filter names) |
| `ActionSchedulerOutboxPublisher` | default `IOutboxPublisher` | `as_schedule_single_action`, `as_enqueue_async_action` | `ActionSchedulerOutboxPublisher.php:22,29` | wp |
| `ProcessRunner::register_event()/register_start()` | runtime registration | `add_action` (priority 99 / 50) | `ProcessRunner.php:77,148` | role C: registrar port |
| `ProcessRunner` lock / scheduling | internals | `$wpdb` GET_LOCK; `as_*`; `WP_CLI` | `ProcessRunner.php:220,394-408,649,665` | role C |
| Four repair handlers | self-consumer ops | `$wpdb`, `ARRAY_A`, `wp_generate_uuid4` | §3.2 | F-22 |
| Four wpdb repositories + `WordPressRepository` | default port implementations | `$wpdb`, `wp_json_encode`, `get_option`/`update_option`, multisite, post meta, `WP_Query` | §3.6 | move wp |
| `WPErrorException` | exception | `\WP_Error` | `WPErrorException.php:10` | move wp |
| `TangibleFieldsRenderer` | renderer | `tangible_fields()` | `TangibleFieldsRenderer.php:21,31` | move wp |
| `WorkflowHandler` | base class | `is_multisite`, `get_current_blog_id` | `WorkflowHandler.php:209` | F-32 |

WP globals read directly from ddd-src: `$wpdb` (13 files), `$GLOBALS['wpdb']` (`TransactionMiddleware.php:25`), and constants `WP_DEBUG` (`OutboxProcessor.php:159`), `WP_CLI` (`ProcessRunner.php:220`), `DOING_CRON` (`CorrelationMiddleware.php:124`), `TANGIBLE_DDD_VERSION` (`Config.php:55`), `ARRAY_A` (`ReplayDeadLetterHandler.php:26`). Calls into `TangibleDDD\WordPress\*`: `Command.php:10`, `CorrelationMiddleware.php:13-15`, `IntegrationListener.php:27`, `OutboxIntegrationEventBus.php:70`. `tangible_*` helpers: `tangible_fields()` only.

---

## 6. composer.json split (in scope: packaging)

Current root (`composer.json`): `php >=8.1`, `symfony/dependency-injection ^7.4`, `league/tactician ^2.0-rc1`, `woocommerce/action-scheduler ^3.9`, `makinacorpus/query-builder ^1.6`, `symfony/config ^7.4`; autoload PSR-4 `TangibleDDD\` → `ddd-src/` + files `tangible-ddd.php`; root-only scripts clone `tangible-datastream` into `.reference/`.

| Package | require | autoload | Notes |
|---|---|---|---|
| ddd-core | `php` (floor to verify: no 8.2+ syntax found in ddd-src by grep for readonly classes, DNF, `true`/`null` standalone types, `#[Override]`, typed constants; `readonly` properties and enums need 8.1), `league/tactician ^2.0-rc1` (F-24), `psr/container ^1.1\|^2.0` (F-25), `psr/log ^1\|^2\|^3` (F-31); suggest `ext-pdo` (for the pdo-default adapter) | PSR-4 `TangibleDDD\` → core src; files: `Domain/Shared/assert.php` only | No Symfony, no AS, no makinacorpus, no `tangible-ddd.php`. |
| ddd-wp | `tangible/ddd-core` (exact matched version, spec contract 5), `woocommerce/action-scheduler ^3.9`, `symfony/dependency-injection ^7.4`, `symfony/config ^7.4` (PHP 8.2 floor comes from here), `makinacorpus/query-builder` if F-27 lands here | PSR-4 for the moved `TangibleDDD\...` FQCNs + `TangibleDDD\WordPress\` → `ddd-wordpress/`; files: loader | Dev: `wp-phpunit`, `yoast/phpunit-polyfills`. |
| ddd-symfony | `tangible/ddd-core`, `doctrine/dbal`, `symfony/messenger`, `symfony/dependency-injection`, `symfony/config`, `symfony/http-kernel` (bundle) | PSR-4 adapter namespace | Postgres only. |
| pdo-default | inside ddd-core under `Defaults/Pdo` (the operator allows a thin adapter in core), or a separate `ddd-pdo` | | Takes a host PDO + table prefix + schema SQL file. No migration runner, no worker. |
| legacy `tangible/ddd` | ddd-core + ddd-wp + loader, same file layout (spec contract 5, review finding 5) | as today | Only this distribution may carry `tangible-ddd.php` in `autoload.files`. |

The root-only `scripts` (`ref:datastream`) and `autoload-dev` `.reference/` stay in the monorepo root.

---

## 7. D1-D14 in this scope

| Demand | Status at 598858c | Where it lands |
|---|---|---|
| D1 ExternalEffect command shape | absent | core CQRS (new command kind + journal port). Needs the transaction boundary (F-04) so `perform()` runs outside it and `record()` inside it. |
| D2 marker-interface subscription | absent (dispatch is by hook string, `WordPressEventDispatcher.php:29`) | core `OrderedListenerDispatcher` (F-05) |
| D5 actor provider | WP-hard-coded (`CorrelationMiddleware.php:120-129`) | core `IActorProvider` (F-03) |
| D8 audit redaction hook | fixed, final (`Redactor.php:8`) | core (F-15) |
| D11 return values | **already works** (probe) | core; docblock only (F-13) |
| D12 per-command audit policy | absent (per-consumer table check, `audit.php:10-25`) | core `AuditPolicy` (F-16) |
| D13 cause event_id + uuid5 | cause reachable via `Correlation::current()->cause` inside the listener scope; no `v5` | core (F-14) |
| D14 post-commit relay wakeup | absent; relay is poll-only (`OutboxProcessor::process_batch`) | core hook after `DomainEventsPublishMiddleware` + transaction commit; symfony adapter |
| D3, D4, D6, D7, D9, D10 | out of scope (process, outbox, codec) | roles B/C |

---

## 8. Test lanes this boundary implies

1. **core-nostub lane.** PHPUnit with a bootstrap that loads only Composer autoload, in a Composer project that has only ddd-core installed. Today's stubless run gives the starting point: 628 tests, 186 errors, 442 passing. Every test that errors on `wpdb` / `add_action` / `is_multisite` either moves to the wp lane or gets rewritten against the new ports. Gate: zero WP symbols defined after autoload (F-01 test).
2. **Static boundary check.** deptrac or phpstan: core may not reference `wpdb`, `WP_*`, `TangibleDDD\WordPress\*`, `Symfony\*`, `MakinaCorpus\*`, `ActionScheduler*`, or `global $`. Domain → Application/Infra allow-list per F-30.
3. **wp lane.** The existing suite with `wp-stubs.php` plus the integration suite (`phpunit.integration.xml`). It must stay green on split packages.
4. **pdo lane.** MySQL 8 only (MariaDB is not claimed). Transaction boundary rollback, and a PDO outbox shared with host domain writes on the same PDO.
5. **Compile test for DI yaml** (F-28).

---

## Open questions

1. **FQCN ownership of `TangibleDDD\Infra\IDDDConfig`** (F-10). It is in core's namespace but carries WP naming semantics, and every consumer implements it. Proposal: core owns it and keeps the WP methods for the compatibility window, with core code calling only the identity subset. Needs an M1 decision.
2. **Do external consumers extend `TangibleDDD\Application\Commands\Command`, `QueryBuilderSelect`, `ISearchableRepository`, or construct `TransactionMiddleware` / `CorrelationMiddleware` by hand?** Not verified. Needs a grep across tangible-cred, lms-monorepo, tangible-datastream, tangible-reporting (0.2 line) and docker-factory (review finding 1).
3. **Tactician stability** (F-24). Is there a stable Tactician 2.x? If not, ddd-core as a transitive dependency needs each root project to require `league/tactician:^2.0-rc1` itself. Confirm with a clean `minimum-stability: stable` install before M1.
4. **Caller-owned transactions** (F-04). When the host already opened a transaction on the shared connection, should `ITransactionBoundary` reject or join with a savepoint? This decides CodeIgniter and plain-PHP ergonomics.
5. **Should the core listener base lose its constructor side effect** (F-09)? The WP `IntegrationListener` must keep registering on construction. Core wants a side-effect-free translator. Two classes, or one class with a registrar?
6. **Where the DI compiler passes live** (F-26). WP containers use Symfony DI too. A single owner is needed for `DDDCompilerPasses` / `LongProcessCatalogPass`, and ddd-symfony must not duplicate them.
7. **Buffer reset timing** (F-17). Was reset-at-start without `finally` intentional? Changing it alters what a failed command's audit `events` field shows.
8. **Does the self-consumer register in `ConsumerRegistry`?** No `ConsumerRegistry::add` or `boot()` was found under `ddd-wordpress/self/`, which is why `Command::container()` overrides the trait (F-06). If it registered, the override could be deleted.
9. **Is `ddd-wordpress/di/tactician.yaml` used by any live consumer**, or only by the scaffolder? It references nonexistent classes (F-28).
10. **pdo-default location.** `Defaults/Pdo` inside ddd-core (the operator allows a thin adapter), or a separate package so `ext-pdo` never appears in core's suggest list?
11. **Is touches indexing (F-07) wanted outside WP?** TXP's D-list does not ask for it. If not, the Symfony host wires `NullFactObserver`.
12. **Actor value shape for D5** (F-03). Keep the `{type, id}` array audit columns, or introduce an `Actor` value with a `kind` enum (user / cli / system / machine) mapped onto the existing `source` column?
