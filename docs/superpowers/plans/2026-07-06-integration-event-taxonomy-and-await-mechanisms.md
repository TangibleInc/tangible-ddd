# Integration Event Taxonomy & Await Mechanisms (0.2.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build tangible-ddd 0.2.0: event taxonomy partition (raisable DomainEvent / derived-only IntegrationEvent), self-publishers, IntegrationBehaviour codec with journey slots, single-door router, IntegrationListener, and the IAwaitMechanism/AwaitAll fan-in with repaired resume plumbing.

**Architecture:** `Event` root splits into raisable `DomainEvent` and unraisable `IntegrationEvent` (severed — not an `IDomainEvent`). All record capability (strict scalarise codec, hydrate, journey slots, identity self-announcement) lives in one `IntegrationBehaviour` trait, used by the severed base (twins) and mixable into scalar `DomainEvent`s (self-publishers). The router has one outbox door (`IAnnouncesIntegration`). The await layer becomes a strategy (`IAwaitMechanism`): `AwaitEvent` refactors onto it unchanged; `AwaitAll` adds fan-in with a process-owned static key extractor, mandatory wall-clock timeout, and GET_LOCK-guarded accumulation. Spec: `docs/superpowers/specs/2026-07-03-integration-event-taxonomy-and-await-mechanisms-design.md`.

**Tech Stack:** PHP 8.2, PHPUnit 10.5 (pure-PHP unit suite, `tests/wp-stubs.php`, no WordPress), Symfony DI (consumer-side, untouched), ActionScheduler (stubbed in tests).

## Global Constraints

- Branch: `release/0.2.0`, cut from `v3-ddd`. PR back into `v3-ddd`.
- **Reversible values** (the only legal record ctor param types): `int|string|bool|float|null`, `BackedEnum`, `DateTimeInterface`, and arrays thereof.
- **Partition:** `IntegrationEvent` must NOT implement `IDomainEvent` (interface OR class chain) — `EventsUnitOfWork::record(IDomainEvent)` must reject twins at type level.
- Hook name formats are FROZEN (wire compat): domain `'{prefix}_domain_{name}'`, integration `'{prefix}_integration_{name}'`, `name()` = class shortname → snake_case.
- Transport keys are FROZEN: `__correlation_id`, `__sequence`, `__event_id` (OutboxProcessor::wrap_payload_for_transport, untouched).
- Journey slots are nullable, never ctor params, never in `integration_payload()`. Stamped at publish AND at hydrate.
- Timeout semantics: pure wall clock. `AwaitAll::timeout_seconds` is REQUIRED (no default). Policies: `AwaitAll::TIMEOUT_FAIL = 'fail'`, `AwaitAll::TIMEOUT_PROCEED = 'proceed'`.
- Every task runs the FULL suite (`vendor/bin/phpunit`) before its commit — existing tests must stay green (208+ tests).
- Run tests from repo root: `wp-content/plugins/tangible-ddd/`. All paths below relative to it.
- Consumer migration (tangible-cred) is OUT OF SCOPE. Do not touch `tangible-cred`.
- Existing runner behavior for `AwaitEvent` (1-of-1, criteria match, resume with event as 2nd step param) must be byte-compatible — `FakeSuspendingProcess` tests pass unmodified except where a task explicitly says otherwise.

## File Structure

```
CREATE ddd-src/Domain/Events/Event.php                      shared root: name() derivation + abstract prefix()
CREATE ddd-src/Domain/Events/IntegrationBehaviour.php            trait: codec + journey slots + integration_action + to_integration default
CREATE ddd-src/Domain/Events/NonReversibleValue.php         exception (strict scalarise)
CREATE ddd-src/Domain/Events/AlreadyIntegrated.php          exception (re-raise guard)
CREATE ddd-src/Domain/Events/IAnnouncesIntegration.php      to_integration(): IIntegrationEvent
CREATE ddd-src/Application/Events/TransportEnvelope.php     __-key unwrap + context restore
CREATE ddd-src/Application/EventHandlers/IntegrationListener.php
CREATE ddd-src/Application/Process/IAwaitMechanism.php
CREATE ddd-src/Application/Process/AwaitAll.php
CREATE ddd-src/Application/Process/Awaits.php               #[Awaits] class attribute
CREATE ddd-src/Application/Process/AwaitedEventNotRegistered.php
MODIFY ddd-src/Domain/Events/DomainEvent.php                extends Event
MODIFY ddd-src/Domain/Events/IIntegrationEvent.php          severed from IDomainEvent; + from_payload()
MODIFY ddd-src/Domain/Events/IntegrationEvent.php           severed base; use IntegrationBehaviour
MODIFY ddd-src/Application/Events/EventRouter.php           single outbox door
MODIFY ddd-src/Application/Events/EventsUnitOfWork.php      AlreadyIntegrated guard
MODIFY ddd-src/Application/EventHandlers/AsyncWordPressActionHandler.php   @deprecated
MODIFY ddd-src/Application/Process/AwaitEvent.php           implements IAwaitMechanism
MODIFY ddd-src/Application/Process/Result.php               await: ?IAwaitMechanism
MODIFY ddd-src/Application/Process/LongProcess.php          await_mechanism state
MODIFY ddd-src/Application/Process/ProcessRunner.php        resume surgery + timeout + guards
MODIFY ddd-src/Infra/Persistence/ProcessRepository.php      await_mechanism column + legacy fallback
MODIFY ddd-wordpress/tables.php                             + await_mechanism JSON NULL
MODIFY ddd-wordpress/integration-events.php                 + integration_listener(); extract_correlation → TransportEnvelope
MODIFY ddd-wordpress/hooks.php                              listener walk + #[Awaits] read + await_timeout hook
MODIFY tests/wp-stubs.php                                   + as_schedule_single_action, remove_test_actions helper
MODIFY .github/workflows/phpunit.yml                        + release/** trigger
CREATE tests/Fakes/FakeResolvedEvent.php                    self-publisher fixture
CREATE tests/Fakes/FakeFatMoment.php + FakeTwinEvent.php    lane-2 fixtures
CREATE tests/Fakes/FakeGatherProcess.php                    AwaitAll saga fixture
CREATE tests/Fakes/FakeCapturingCommand.php                 ICommand recording send()
MODIFY tests/Fakes/FakeIntegrationEvent.php                 becomes ctor-only record
```

---

### Task 1: `Event` root + re-parent `DomainEvent` (pure refactor)

**Files:**
- Create: `ddd-src/Domain/Events/Event.php`
- Modify: `ddd-src/Domain/Events/DomainEvent.php`
- Test: existing suite (no new tests — behavior identical)

**Interfaces:**
- Consumes: nothing new.
- Produces: `abstract class TangibleDDD\Domain\Events\Event` with `abstract protected static function prefix(): string` and `public static function name(): string` (shortname → snake_case). Task 3's `IntegrationEvent` will extend it.

- [ ] **Step 1: Create the branch (once, before any work)**

```bash
git checkout v3-ddd && git pull && git checkout -b release/0.2.0
```

- [ ] **Step 2: Create `Event.php`**

```php
<?php

namespace TangibleDDD\Domain\Events;

/**
 * Shared root of the event partition: name/prefix machinery only.
 * DomainEvent (raisable) and IntegrationEvent (derived-only record)
 * both extend this and NOTHING else is shared between them.
 */
abstract class Event {

  /** Consumer provides the prefix via generated base class. */
  abstract protected static function prefix(): string;

  /**
   * Short event name. Default: derive from class name (UserEarned -> user_earned).
   */
  public static function name(): string {
    $class = (new \ReflectionClass(static::class))->getShortName();
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
  }
}
```

- [ ] **Step 3: Rewrite `DomainEvent.php` to extend the root**

Replace the whole file body with:

```php
<?php

namespace TangibleDDD\Domain\Events;

/**
 * Base class for domain events — the RAISABLE species. Validity is bounded
 * by the request: instances die pre-ActionScheduler, always.
 *
 * Consumer plugins extend this in their generated DomainEvent base,
 * providing the prefix() method.
 */
abstract class DomainEvent extends Event implements IDomainEvent {

  /**
   * WordPress action name for domain event publishing.
   */
  public static function action(): string {
    return static::prefix() . '_domain_' . static::name();
  }

  /**
   * Event payload. Override in concrete events.
   */
  abstract public function payload(): array;
}
```

(`name()` and `prefix()` move up to `Event`; `action()` stays here — the domain hook belongs to the raisable species.)

- [ ] **Step 4: Run full suite**

Run: `vendor/bin/phpunit`
Expected: ALL PASS (pure refactor; `IntegrationEvent` still extends `DomainEvent` at this point and inherits through it).

- [ ] **Step 5: Commit**

```bash
git add ddd-src/Domain/Events/Event.php ddd-src/Domain/Events/DomainEvent.php
git commit -m "refactor(events): extract Event root (name/prefix) above DomainEvent"
```

---

### Task 2: `IntegrationBehaviour` trait + `NonReversibleValue`

**Files:**
- Create: `ddd-src/Domain/Events/IntegrationBehaviour.php`, `ddd-src/Domain/Events/NonReversibleValue.php`
- Create: `tests/Fakes/FakeOutcome.php` (backed enum), `tests/Fakes/FakeResolvedEvent.php` (self-publisher fixture — full shape lands in Task 4; here it only needs the trait + DomainEvent)
- Test: `tests/Unit/Events/IntegrationBehaviourTest.php`

**Interfaces:**
- Consumes: `Event` root (Task 1) — trait host classes resolve `static::prefix()`/`static::name()` through it.
- Produces (used by Tasks 3,4,6,9,11):
  - `trait TangibleDDD\Domain\Events\IntegrationBehaviour` with:
    - `public function integration_payload(): array` — named reversible scalars from promoted ctor props; throws `NonReversibleValue`
    - `public static function from_payload(array $payload): static` — named lookup + type coercion via ctor
    - `public static function integration_action(): string` — `'{prefix}_integration_{name}'`
    - `public function to_integration(): static` — returns `$this` (identity announcement)
    - `public function correlation_id(): ?string`, `public function event_id(): ?string` — journey slots, null until stamped
    - `final public function stamp_journey(string $correlation_id, string $event_id): void`
    - `public function delay(): int` (0), `public function is_unique(): bool` (false)
  - `TangibleDDD\Domain\Events\NonReversibleValue extends \DomainException` — ctor `(string $event_class, string $param, string $actual_type)`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Events/IntegrationBehaviourTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Events\NonReversibleValue;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class IntegrationBehaviourTest extends TestCase {

  public function test_integration_payload_is_named_scalars(): void {
    $e = new FakeResolvedEvent(request_id: 312, outcome: FakeOutcome::Accepted, resolved_at: new \DateTimeImmutable('2026-07-06T10:00:00+00:00'));
    $this->assertSame(
      ['request_id' => 312, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00', 'extra' => []],
      $e->integration_payload()
    );
  }

  public function test_round_trip_law(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00'), ['a' => 1]);
    $back = FakeResolvedEvent::from_payload($e->integration_payload());
    $this->assertSame($e->integration_payload(), $back->integration_payload());
    $this->assertSame(FakeOutcome::Accepted, $back->outcome);
    $this->assertInstanceOf(\DateTimeImmutable::class, $back->resolved_at);
  }

  public function test_hydrate_ignores_transport_keys(): void {
    $back = FakeResolvedEvent::from_payload([
      'request_id' => 312, 'outcome' => 'accepted',
      'resolved_at' => '2026-07-06T10:00:00+00:00',
      '__correlation_id' => 'abc', '__sequence' => 3, '__event_id' => 'ev-1',
    ]);
    $this->assertSame(312, $back->request_id);
  }

  public function test_scalarise_throws_on_non_reversible_value(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable(), ['bad' => new \stdClass()]);
    $this->expectException(NonReversibleValue::class);
    $e->integration_payload();
  }

  public function test_journey_slots_null_until_stamped_then_readable(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->assertNull($e->correlation_id());
    $this->assertNull($e->event_id());
    $e->stamp_journey('corr-1', 'ev-1');
    $this->assertSame('corr-1', $e->correlation_id());
    $this->assertSame('ev-1', $e->event_id());
  }

  public function test_journey_slots_never_enter_payload(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00'));
    $e->stamp_journey('corr-1', 'ev-1');
    $this->assertArrayNotHasKey('correlation_id', $e->integration_payload());
    $this->assertArrayNotHasKey('event_id', $e->integration_payload());
  }

  public function test_identity_announcement(): void {
    $e = new FakeResolvedEvent(312, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->assertSame($e, $e->to_integration());
  }

  public function test_integration_action_name(): void {
    $this->assertSame('test_integration_fake_resolved_event', FakeResolvedEvent::integration_action());
  }
}
```

`tests/Fakes/FakeOutcome.php`:

```php
<?php

namespace TangibleDDD\Tests\Fakes;

enum FakeOutcome: string {
  case Accepted = 'accepted';
  case Rejected = 'rejected';
}
```

`tests/Fakes/FakeResolvedEvent.php` (interfaces added in Task 4; the trait works standalone):

```php
<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

class FakeResolvedEvent extends DomainEvent {
  use IntegrationBehaviour;

  public function __construct(
    public readonly int $request_id,
    public readonly FakeOutcome $outcome,
    public readonly \DateTimeImmutable $resolved_at,
    public readonly array $extra = [],
  ) {}

  protected static function prefix(): string { return 'test'; }

  public function payload(): array { return $this->integration_payload(); }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter IntegrationBehaviourTest`
Expected: FAIL — `Trait "TangibleDDD\Domain\Events\IntegrationBehaviour" not found`.

- [ ] **Step 3: Implement `NonReversibleValue.php`**

```php
<?php

namespace TangibleDDD\Domain\Events;

final class NonReversibleValue extends \DomainException {
  public function __construct(string $event_class, string $param, string $actual_type) {
    parent::__construct(
      "$event_class::\$$param holds $actual_type — integration events are composed of reversible values only " .
      "(int|string|bool|float|null, BackedEnum, DateTimeInterface, arrays thereof). " .
      "Fat facts belong on a DomainEvent that announces a scalar record (IAnnouncesIntegration)."
    );
  }
}
```

- [ ] **Step 4: Implement `IntegrationBehaviour.php`**

```php
<?php

namespace TangibleDDD\Domain\Events;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The record capability: strict scalarise codec (ctor IS the schema),
 * total hydration, journey slots, identity announcement.
 *
 * Used by the IntegrationEvent base (twins) and mixed into scalar
 * DomainEvents (self-publishers). Host class must provide static prefix()
 * and name() (both via the Event root).
 */
trait IntegrationBehaviour {

  private ?string $journey_correlation_id = null;
  private ?string $journey_event_id = null;

  public static function integration_action(): string {
    return static::prefix() . '_integration_' . static::name();
  }

  // ── scalarise: the membrane, strict ─────────────────────────────────
  /** @throws NonReversibleValue */
  public function integration_payload(): array {
    $out = [];
    foreach (self::record_schema() as $param) {
      $name = $param->getName();
      $out[$name] = self::scalarise_value($this->{$name}, $name);
    }
    return $out;
  }

  // ── hydrate: the return ticket ──────────────────────────────────────
  public static function from_payload(array $payload): static {
    $args = [];
    foreach (self::record_schema() as $param) {
      $name = $param->getName();
      $raw = array_key_exists($name, $payload)
        ? $payload[$name]
        : ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
      $type = $param->getType();
      $args[$name] = self::revive($type instanceof ReflectionNamedType ? $type : null, $raw);
    }
    return new static(...$args);
  }

  // ── journey slots (never ctor params, never in the payload) ─────────
  public function correlation_id(): ?string { return $this->journey_correlation_id; }
  public function event_id(): ?string { return $this->journey_event_id; }

  final public function stamp_journey(string $correlation_id, string $event_id): void {
    $this->journey_correlation_id = $correlation_id;
    $this->journey_event_id = $event_id;
  }

  // ── identity announcement (self-publishers; twins never announce) ───
  public function to_integration(): static {
    return $this;
  }

  public function delay(): int { return 0; }
  public function is_unique(): bool { return false; }

  // ── internals ───────────────────────────────────────────────────────
  /** @throws NonReversibleValue */
  private static function scalarise_value(mixed $v, string $param): mixed {
    return match (true) {
      $v === null || is_scalar($v)    => $v,
      $v instanceof BackedEnum         => $v->value,
      $v instanceof DateTimeInterface  => $v->format('c'),
      is_array($v)                     => array_map(
        fn($e) => self::scalarise_value($e, $param), $v
      ),
      default => throw new NonReversibleValue(static::class, $param, get_debug_type($v)),
    };
  }

  private static function revive(?ReflectionNamedType $type, mixed $raw): mixed {
    if ($raw === null || $type === null) {
      return $raw;
    }
    $t = $type->getName();
    return match (true) {
      $t === 'int'    => (int) $raw,
      $t === 'float'  => (float) $raw,
      $t === 'string' => (string) $raw,
      $t === 'bool'   => (bool) $raw,
      is_a($t, BackedEnum::class, true)        => $t::from($raw),
      is_a($t, DateTimeInterface::class, true) => new DateTimeImmutable($raw),
      default => $raw,
    };
  }

  /** @return ReflectionParameter[] */
  private static function record_schema(): array {
    static $schema = [];
    return $schema[static::class]
      ??= (new ReflectionClass(static::class))->getConstructor()?->getParameters() ?? [];
  }
}
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit --filter IntegrationBehaviourTest`
Expected: 8 tests PASS.

- [ ] **Step 6: Full suite, then commit**

```bash
vendor/bin/phpunit
git add ddd-src/Domain/Events/IntegrationBehaviour.php ddd-src/Domain/Events/NonReversibleValue.php tests/Fakes/FakeOutcome.php tests/Fakes/FakeResolvedEvent.php tests/Unit/Events/IntegrationBehaviourTest.php
git commit -m "feat(events): IntegrationBehaviour trait — strict scalarise codec, journey slots, identity announcement"
```

---

### Task 3: Sever the partition — `IIntegrationEvent` + `IntegrationEvent`

**Files:**
- Modify: `ddd-src/Domain/Events/IIntegrationEvent.php`, `ddd-src/Domain/Events/IntegrationEvent.php`
- Modify: `tests/Fakes/FakeIntegrationEvent.php`
- Modify: `tests/Unit/Events/DomainEventTest.php` (the `from_payload` round-trip test now goes through the trait; adjust only if it constructs via `payload()`)
- Test: `tests/Unit/Events/PartitionTest.php`

**Interfaces:**
- Consumes: `Event` (Task 1), `IntegrationBehaviour` (Task 2).
- Produces:
  - `interface IIntegrationEvent` — **standalone, no longer extends IDomainEvent**: `name(): string`, `integration_action(): string`, `integration_payload(): array`, `from_payload(array): static`, `delay(): int`, `is_unique(): bool`, `correlation_id(): ?string`, `event_id(): ?string`, `stamp_journey(string, string): void`.
  - `abstract class IntegrationEvent extends Event implements IIntegrationEvent { use IntegrationBehaviour; }` — UNRAISABLE (not IDomainEvent, no `action()`, no `payload()`).
  - `FakeIntegrationEvent extends IntegrationEvent` — ctor-only `(int $entity_id = 1, string $action_type = 'synced')`, hand-written `payload()`/`from_payload()` DELETED.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Events/PartitionTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeOutcome;

class PartitionTest extends TestCase {

  public function test_twin_is_not_a_domain_event(): void {
    $twin = new FakeIntegrationEvent(entity_id: 42);
    $this->assertInstanceOf(IIntegrationEvent::class, $twin);
    $this->assertNotInstanceOf(IDomainEvent::class, $twin);
  }

  public function test_integration_event_base_has_no_domain_hook(): void {
    $this->assertFalse(method_exists(IntegrationEvent::class, 'action'));
    $this->assertFalse(method_exists(IntegrationEvent::class, 'payload'));
  }

  public function test_twin_round_trips_via_trait(): void {
    $twin = new FakeIntegrationEvent(entity_id: 42, action_type: 'synced');
    $back = FakeIntegrationEvent::from_payload($twin->integration_payload());
    $this->assertSame(42, $back->entity_id);
    $this->assertSame('synced', $back->action_type);
  }

  public function test_self_publisher_is_both(): void {
    $e = new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->assertInstanceOf(IDomainEvent::class, $e);
    $this->assertInstanceOf(IIntegrationEvent::class, $e);
  }

  public function test_hook_names_frozen(): void {
    $this->assertSame('test_integration_fake_integration_event', FakeIntegrationEvent::integration_action());
  }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter PartitionTest`
Expected: FAIL — `FakeIntegrationEvent` is still an `IDomainEvent` (old inheritance), `FakeResolvedEvent` doesn't implement `IIntegrationEvent` yet.

- [ ] **Step 3: Rewrite `IIntegrationEvent.php`**

```php
<?php

namespace TangibleDDD\Domain\Events;

/**
 * The record contract: an event composed of reversible values, engineered so
 * instances exist on BOTH sides of the ActionScheduler hop.
 *
 * SEVERED from IDomainEvent (0.2.0 partition): a class implementing ONLY this
 * interface cannot be raised — EventsUnitOfWork::record() types IDomainEvent.
 * Self-publishers implement both (extends DomainEvent + this interface).
 */
interface IIntegrationEvent {

  public static function name(): string;

  /** WordPress action name for the integration (async) surface. */
  public static function integration_action(): string;

  /** Named array of reversible scalars. @throws NonReversibleValue */
  public function integration_payload(): array;

  /** The return ticket — total for every conforming record. */
  public static function from_payload(array $payload): static;

  /** Journey slots — null until stamped (at publish and at hydrate). */
  public function correlation_id(): ?string;
  public function event_id(): ?string;
  public function stamp_journey(string $correlation_id, string $event_id): void;

  public function delay(): int;
  public function is_unique(): bool;
}
```

- [ ] **Step 4: Rewrite `IntegrationEvent.php`**

```php
<?php

namespace TangibleDDD\Domain\Events;

/**
 * The derived-only record base — twins extend this.
 *
 * NOT a DomainEvent: not raisable (record() rejects it at type level), owns no
 * domain hook. Exists only as the product of IAnnouncesIntegration::to_integration().
 * Consumer plugins re-parent their generated IntegrationEvent base here (it keeps
 * only prefix()).
 */
abstract class IntegrationEvent extends Event implements IIntegrationEvent {
  use IntegrationBehaviour;
}
```

(The entire v0.1 body — `scalarise()`, `integration_payload()`, `delay()`, `is_unique()` — is deleted; the trait supplies the strict replacements.)

- [ ] **Step 5: Rewrite `tests/Fakes/FakeIntegrationEvent.php`**

```php
<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

class FakeIntegrationEvent extends IntegrationEvent {
  public function __construct(
    public readonly int $entity_id = 1,
    public readonly string $action_type = 'synced'
  ) {}

  protected static function prefix(): string { return 'test'; }
}
```

- [ ] **Step 6: Add the interfaces to `FakeResolvedEvent`**

In `tests/Fakes/FakeResolvedEvent.php` change the class line to:

```php
use TangibleDDD\Domain\Events\IIntegrationEvent;

class FakeResolvedEvent extends DomainEvent implements IIntegrationEvent {
```

- [ ] **Step 7: Fix collateral in the existing suite**

Run: `vendor/bin/phpunit` and fix ONLY these expected breaks:
- `tests/Unit/Events/DomainEventTest.php::test_from_payload_reconstructs_event` — it calls `FakeIntegrationEvent::from_payload($original->payload())`; `payload()` no longer exists on the fake. Change to `FakeIntegrationEvent::from_payload($original->integration_payload())` and keep the assertions on `entity_id`/`action_type`.
- Any test constructing `FakeIntegrationEvent` positionally still works (ctor unchanged).
- `FakeSuspendingProcess` (`await: new AwaitEvent(FakeIntegrationEvent::class, ...)`) — `AwaitEvent`'s ctor guard `is_a($event_class, IIntegrationEvent::class, true)` still passes (fake still implements it). ProcessRunner's `register_event` guard likewise. No changes.
- If `EventRouter`/`EventsUnitOfWork` tests dispatch a `FakeIntegrationEvent` as a domain event (it no longer is one), rewrite those cases to use `FakeResolvedEvent` (a raisable record). Do NOT change router/UoW production code in this task.

Expected: full suite green.

- [ ] **Step 8: Commit**

```bash
git add -A ddd-src/Domain/Events tests
git commit -m "feat(events)!: sever the partition — IntegrationEvent is a derived-only record, not a DomainEvent"
```

---

### Task 4: Single-door router + `IAnnouncesIntegration` + `AlreadyIntegrated` guard

**Files:**
- Create: `ddd-src/Domain/Events/IAnnouncesIntegration.php`, `ddd-src/Domain/Events/AlreadyIntegrated.php`
- Create: `tests/Fakes/FakeFatMoment.php`, `tests/Fakes/FakeTwinEvent.php`
- Modify: `ddd-src/Application/Events/EventRouter.php`, `ddd-src/Application/Events/EventsUnitOfWork.php`
- Modify: `ddd-src/Infra/Services/OutboxIntegrationEventBus.php` (journey stamp at publish)
- Test: `tests/Unit/Events/EventRouterTest.php` (extend existing or create), `tests/Unit/Events/AlreadyIntegratedTest.php`

**Interfaces:**
- Consumes: `IIntegrationEvent` (Task 3), `IntegrationBehaviour::to_integration()` (Task 2).
- Produces:
  - `interface IAnnouncesIntegration { public function to_integration(): IIntegrationEvent; }`
  - `AlreadyIntegrated extends \LogicException` — ctor `(string $event_class, string $event_id)`
  - Router rule: `dispatcher->dispatch($event)` always; `instanceof IAnnouncesIntegration → bus->publish($event->to_integration())`.
  - `FakeFatMoment` (DomainEvent + IAnnouncesIntegration, holds `\stdClass $entity`, announces `FakeTwinEvent`), `FakeTwinEvent extends IntegrationEvent` (`int $entity_id`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Events/AlreadyIntegratedTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Events\AlreadyIntegrated;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AlreadyIntegratedTest extends TestCase {

  public function test_fresh_self_publisher_records_fine(): void {
    $uow = new EventsUnitOfWork();
    $uow->record(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));
    $this->assertCount(1, $uow->drain());
  }

  public function test_stamped_reconstruction_is_rejected(): void {
    $e = FakeResolvedEvent::from_payload(['request_id' => 1, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00']);
    $e->stamp_journey('corr-1', 'ev-1');   // hydration path stamps — this IS a traveled fact
    $uow = new EventsUnitOfWork();
    $this->expectException(AlreadyIntegrated::class);
    $uow->record($e);
  }
}
```

Add to (or create) `tests/Unit/Events/EventRouterTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeFatMoment;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeTwinEvent;

class EventRouterTest extends TestCase {

  private array $dispatched = [];
  private array $published = [];
  private EventRouter $router;

  protected function setUp(): void {
    $dispatcher = new class($this->dispatched) implements IDomainEventDispatcher {
      public function __construct(private array &$log) {}
      public function dispatch(IDomainEvent $event): void { $this->log[] = $event; }
    };
    $bus = new class($this->published) implements IIntegrationEventBus {
      public function __construct(private array &$log) {}
      public function publish(IIntegrationEvent $event): void { $this->log[] = $event; }
    };
    $this->router = new EventRouter($dispatcher, $bus);
  }

  public function test_plain_domain_event_never_reaches_bus(): void {
    $this->router->publish(new \TangibleDDD\Tests\Fakes\FakeDomainEvent());
    $this->assertCount(1, $this->dispatched);
    $this->assertCount(0, $this->published);
  }

  public function test_self_publisher_hits_both_surfaces_as_same_object(): void {
    $e = new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable());
    $this->router->publish($e);
    $this->assertSame([$e], $this->dispatched);
    $this->assertSame([$e], $this->published);   // identity announcement
  }

  public function test_fat_moment_dispatches_itself_and_publishes_its_twin(): void {
    $moment = new FakeFatMoment(entity: (object)['id' => 42]);
    $this->router->publish($moment);
    $this->assertSame([$moment], $this->dispatched);
    $this->assertCount(1, $this->published);
    $this->assertInstanceOf(FakeTwinEvent::class, $this->published[0]);
    $this->assertSame(42, $this->published[0]->entity_id);
  }
}
```

(If `tests/Fakes/FakeDomainEvent.php` doesn't exist, create it: `class FakeDomainEvent extends DomainEvent { protected static function prefix(): string { return 'test'; } public function payload(): array { return []; } }`.)

`tests/Fakes/FakeFatMoment.php`:

```php
<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;

class FakeFatMoment extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly object $entity) {}
  protected static function prefix(): string { return 'test'; }
  public function payload(): array { return [$this->entity]; }
  public function to_integration(): FakeTwinEvent {
    return new FakeTwinEvent(entity_id: (int) $this->entity->id);
  }
}
```

`tests/Fakes/FakeTwinEvent.php`:

```php
<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Domain\Events\IntegrationEvent;

class FakeTwinEvent extends IntegrationEvent {
  public function __construct(public readonly int $entity_id) {}
  protected static function prefix(): string { return 'test'; }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'AlreadyIntegratedTest|EventRouterTest'`
Expected: FAIL — `IAnnouncesIntegration` not found / router still branches on `IIntegrationEvent` / no guard.

- [ ] **Step 3: Create `IAnnouncesIntegration.php`**

```php
<?php

namespace TangibleDDD\Domain\Events;

/**
 * A raisable event announcing that a record will exist. Implementors: fat
 * moments (return their derived twin — hand-written fact selection + naming,
 * the irreducible five lines) and scalar self-publishers (IntegrationBehaviour's
 * default returns $this). Narrow your return type — it IS the twin announcement.
 */
interface IAnnouncesIntegration {
  public function to_integration(): IIntegrationEvent;
}
```

- [ ] **Step 4: Create `AlreadyIntegrated.php`**

```php
<?php

namespace TangibleDDD\Domain\Events;

final class AlreadyIntegrated extends \LogicException {
  public function __construct(string $event_class, string $event_id) {
    parent::__construct(
      "$event_class (event_id: $event_id) already traveled — you are re-raising a reconstruction. " .
      "Re-delivery of a traveled fact is REPLAY (through the outbox), never raising."
    );
  }
}
```

- [ ] **Step 5: Rewrite `EventRouter::publish`**

```php
public function publish(IDomainEvent $event): void {
  $this->dispatcher->dispatch($event);

  if ($event instanceof IAnnouncesIntegration) {
    $this->bus->publish($event->to_integration());
  }
}
```

(Add `use TangibleDDD\Domain\Events\IAnnouncesIntegration;`; drop the `IIntegrationEvent` import if now unused. Update the class docblock: "1. All domain events go to the dispatcher; 2. Announcing events additionally send their record to the bus.")

- [ ] **Step 6: Add the guard to `EventsUnitOfWork::record`**

```php
public function record(IDomainEvent $event): void {
  if ($event instanceof IIntegrationEvent && $event->event_id() !== null) {
    throw new AlreadyIntegrated(get_class($event), $event->event_id());
  }
  if ($this->sealed && !$event instanceof IIntegrationEvent) {
    throw new DomainEventAfterSealException(get_class($event));
  }
  $this->queued[] = $event;
}
```

(Add `use TangibleDDD\Domain\Events\AlreadyIntegrated;`.)

- [ ] **Step 7: Stamp the journey at publish — `OutboxIntegrationEventBus::publish`**

The outbox row's `event_id` is generated inside `IOutboxRepository::write()`. Change the bus to stamp AFTER the write, using the id the write returns — check `IOutboxRepository::write()`'s return; if it returns the event_id (string/UUID), use it directly:

```php
public function publish(IIntegrationEvent $event): void {
  if ($event->is_unique()) {
    $this->outbox->cancel_duplicates($event::name(), $event->integration_payload());
  }

  $event_id = $this->outbox->write(
    $event,
    CorrelationContext::get(),
    CorrelationContext::command_id()
  );

  if (is_string($event_id) && $event_id !== '' && $event->event_id() === null) {
    $event->stamp_journey(CorrelationContext::get(), $event_id);
  }
}
```

If `write()` returns void/int-row-id instead, read `OutboxRepository::write()` first and stamp with whatever uniquely identifies the event row (the generated event UUID) — expose it via the return value in the same edit. Do not invent a second id.

- [ ] **Step 8: Run to verify pass, full suite, commit**

```bash
vendor/bin/phpunit --filter 'AlreadyIntegratedTest|EventRouterTest'   # PASS
vendor/bin/phpunit                                                    # ALL PASS
git add -A ddd-src tests
git commit -m "feat(events): single-door router (IAnnouncesIntegration) + AlreadyIntegrated re-raise guard + publish-time journey stamp"
```

---

### Task 5: `TransportEnvelope` + refactor `extract_correlation`

**Files:**
- Create: `ddd-src/Application/Events/TransportEnvelope.php`
- Modify: `ddd-wordpress/integration-events.php`
- Test: `tests/Unit/Events/TransportEnvelopeTest.php`

**Interfaces:**
- Consumes: `CorrelationContext` (`init(?string)`, `set_sequence(int)`, `set_causation(string, string)`).
- Produces (used by Tasks 6 and 9):
  - `final class TransportEnvelope` with readonly props `array $payload`, `?string $correlation_id`, `?int $sequence`, `?string $event_id`
  - `public static function unwrap(array $wrapped): self` — strips `__`-keys into props, rest into `$payload`
  - `public function restore_context(): void` — `CorrelationContext::init($this->correlation_id)` (when set), `set_sequence`, `set_causation($this->event_id, 'integration_event')` (when set)

- [ ] **Step 1: Write the failing test**

`tests/Unit/Events/TransportEnvelopeTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\TransportEnvelope;

class TransportEnvelopeTest extends TestCase {

  protected function setUp(): void { CorrelationContext::reset(); }
  protected function tearDown(): void { CorrelationContext::reset(); }

  public function test_unwrap_separates_journey_from_fact(): void {
    $env = TransportEnvelope::unwrap([
      'request_id' => 312, 'outcome' => 'accepted',
      '__correlation_id' => 'corr-1', '__sequence' => 3, '__event_id' => 'ev-9',
    ]);
    $this->assertSame(['request_id' => 312, 'outcome' => 'accepted'], $env->payload);
    $this->assertSame('corr-1', $env->correlation_id);
    $this->assertSame(3, $env->sequence);
    $this->assertSame('ev-9', $env->event_id);
  }

  public function test_unwrap_without_transport_keys(): void {
    $env = TransportEnvelope::unwrap(['a' => 1]);
    $this->assertSame(['a' => 1], $env->payload);
    $this->assertNull($env->correlation_id);
    $this->assertNull($env->event_id);
  }

  public function test_restore_context_inits_correlation_and_causation(): void {
    $env = TransportEnvelope::unwrap(['a' => 1, '__correlation_id' => 'corr-1', '__event_id' => 'ev-9']);
    $env->restore_context();
    $this->assertSame('corr-1', CorrelationContext::peek());
  }
}
```

- [ ] **Step 2: Verify failure** — `vendor/bin/phpunit --filter TransportEnvelopeTest` → class not found.

- [ ] **Step 3: Implement `TransportEnvelope.php`**

```php
<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Application\Correlation\CorrelationContext;

/**
 * The journey's wire form. OutboxProcessor smears __-keys into the payload bag
 * for transport; this is the single place they are separated back out.
 * Used by the WP integration_action() helper, the listener ceremony, and the
 * process runner's wake path.
 */
final class TransportEnvelope {

  private function __construct(
    public readonly array $payload,
    public readonly ?string $correlation_id,
    public readonly ?int $sequence,
    public readonly ?string $event_id,
  ) {}

  public static function unwrap(array $wrapped): self {
    $correlation_id = isset($wrapped['__correlation_id']) ? (string) $wrapped['__correlation_id'] : null;
    $sequence = isset($wrapped['__sequence']) ? (int) $wrapped['__sequence'] : null;
    $event_id = isset($wrapped['__event_id']) ? (string) $wrapped['__event_id'] : null;
    unset($wrapped['__correlation_id'], $wrapped['__sequence'], $wrapped['__event_id']);

    return new self($wrapped, $correlation_id, $sequence, $event_id);
  }

  /** Restore ambient correlation + stash this event as causation for dispatched commands. */
  public function restore_context(): void {
    if ($this->correlation_id !== null) {
      CorrelationContext::init($this->correlation_id);
    }
    if ($this->sequence !== null) {
      CorrelationContext::set_sequence($this->sequence);
    }
    if ($this->event_id !== null) {
      CorrelationContext::set_causation($this->event_id, 'integration_event');
    }
  }
}
```

- [ ] **Step 4: Refactor `extract_correlation` in `ddd-wordpress/integration-events.php` to delegate**

Replace the body of `extract_correlation(array $params): array` with:

```php
function extract_correlation(array $params): array {
  if (
    count($params) === 1 &&
    is_array($params[0]) &&
    isset($params[0]['__correlation_id'])
  ) {
    $envelope = \TangibleDDD\Application\Events\TransportEnvelope::unwrap($params[0]);
    $envelope->restore_context();

    // Positional list payloads spread as positional args; associative payloads
    // pass through intact as a single arg (see array_is_list gate rationale).
    return array_is_list($envelope->payload) ? array_values($envelope->payload) : [$envelope->payload];
  }

  return $params;
}
```

- [ ] **Step 5: Verify pass + full suite + commit**

```bash
vendor/bin/phpunit --filter TransportEnvelopeTest && vendor/bin/phpunit
git add ddd-src/Application/Events/TransportEnvelope.php ddd-wordpress/integration-events.php tests/Unit/Events/TransportEnvelopeTest.php
git commit -m "feat(events): TransportEnvelope — single unwrap point for journey transport keys"
```

---

### Task 6: `integration_listener()` ceremony + `IntegrationListener` base + auto-wire + Async deprecation

**Files:**
- Create: `ddd-src/Application/EventHandlers/IntegrationListener.php`
- Create: `tests/Fakes/FakeCapturingCommand.php`, `tests/Fakes/FakeRecordingListener.php`
- Modify: `ddd-wordpress/integration-events.php` (add `integration_listener()`), `ddd-wordpress/hooks.php` (walk `\Application\IntegrationListeners\`), `ddd-src/Application/EventHandlers/AsyncWordPressActionHandler.php` (@deprecated)
- Test: `tests/Unit/EventHandlers/IntegrationListenerTest.php`

**Interfaces:**
- Consumes: `TransportEnvelope` (Task 5), `IIntegrationEvent::from_payload/stamp_journey` (Task 3), `TangibleDDD\Application\Commands\ICommand`.
- Produces:
  - `function TangibleDDD\WordPress\integration_listener(string $event_class, callable $translate): void` — hooks `$event_class::integration_action()`, on fire: unwrap → restore context → `from_payload` → `stamp_journey` (when event_id present) → `$cmd = $translate($event)` → `$cmd?->send()`
  - `abstract class IntegrationListener` — `abstract protected function get_event_class(): string;` `abstract protected function get_command(IIntegrationEvent $event): ?ICommand;` self-wiring ctor delegating to `integration_listener()`.

- [ ] **Step 1: Write the failing test**

`tests/Fakes/FakeCapturingCommand.php`:

```php
<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Commands\ICommand;

class FakeCapturingCommand implements ICommand {
  /** @var array<self> */
  public static array $sent = [];

  public function __construct(public readonly int $request_id) {}

  public function send(): mixed {
    self::$sent[] = $this;
    return null;
  }
}
```

(Check `ICommand`'s actual method list in `ddd-src/Application/Commands/ICommand.php` first; implement exactly what it declares — if it declares nothing beyond `send()`, the above stands; if `send()` isn't on the interface, keep the method anyway — the ceremony calls `->send()`.)

`tests/Fakes/FakeRecordingListener.php`:

```php
<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Domain\Events\IIntegrationEvent;

class FakeRecordingListener extends IntegrationListener {
  public static ?IIntegrationEvent $received = null;

  protected function get_event_class(): string {
    return FakeResolvedEvent::class;
  }

  protected function get_command(IIntegrationEvent $event): ?ICommand {
    self::$received = $event;
    /** @var FakeResolvedEvent $event */
    return new FakeCapturingCommand($event->request_id);
  }
}
```

`tests/Unit/EventHandlers/IntegrationListenerTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\EventHandlers;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Tests\Fakes\FakeCapturingCommand;
use TangibleDDD\Tests\Fakes\FakeRecordingListener;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class IntegrationListenerTest extends TestCase {

  protected function setUp(): void {
    global $_test_actions;
    $_test_actions = [];
    FakeCapturingCommand::$sent = [];
    FakeRecordingListener::$received = null;
    CorrelationContext::reset();
  }

  public function test_ceremony_delivers_typed_stamped_event_and_sends_command(): void {
    new FakeRecordingListener();   // ctor self-wires

    do_action(FakeResolvedEvent::integration_action(), [
      'request_id' => 312, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00',
      '__correlation_id' => 'corr-1', '__sequence' => 2, '__event_id' => 'ev-9',
    ]);

    $received = FakeRecordingListener::$received;
    $this->assertInstanceOf(FakeResolvedEvent::class, $received);
    $this->assertSame(312, $received->request_id);
    $this->assertSame('corr-1', $received->correlation_id());   // journey stamped at hydrate
    $this->assertSame('ev-9', $received->event_id());
    $this->assertCount(1, FakeCapturingCommand::$sent);
    $this->assertSame(312, FakeCapturingCommand::$sent[0]->request_id);
    $this->assertSame('corr-1', CorrelationContext::peek());    // context restored for the send
  }

  public function test_null_command_is_a_no_op(): void {
    \TangibleDDD\WordPress\integration_listener(FakeResolvedEvent::class, fn($e) => null);
    do_action(FakeResolvedEvent::integration_action(), ['request_id' => 1, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00']);
    $this->assertCount(0, FakeCapturingCommand::$sent);
  }
}
```

- [ ] **Step 2: Verify failure** — `vendor/bin/phpunit --filter IntegrationListenerTest` → class/function not found.

- [ ] **Step 3: Add `integration_listener()` to `ddd-wordpress/integration-events.php`**

```php
/**
 * The integration-listener ceremony: hook a record's integration action,
 * rebuild the typed event, restore journey context, translate to a Command.
 *
 * This is the internal primitive; the paved road is the IntegrationListener
 * base class (named, enumerable, DI-constructed). Fn-form = escape hatch.
 *
 * @param class-string<\TangibleDDD\Domain\Events\IIntegrationEvent> $event_class
 * @param callable(\TangibleDDD\Domain\Events\IIntegrationEvent): ?\TangibleDDD\Application\Commands\ICommand $translate
 */
function integration_listener(string $event_class, callable $translate): void {
  if (!is_a($event_class, \TangibleDDD\Domain\Events\IIntegrationEvent::class, true)) {
    throw new \InvalidArgumentException("$event_class must implement IIntegrationEvent");
  }

  add_action($event_class::integration_action(), function (array $wrapped) use ($event_class, $translate) {
    $envelope = \TangibleDDD\Application\Events\TransportEnvelope::unwrap($wrapped);
    $envelope->restore_context();

    $event = $event_class::from_payload($envelope->payload);
    if ($envelope->event_id !== null) {
      $event->stamp_journey((string) $envelope->correlation_id, $envelope->event_id);
    }

    $command = $translate($event);
    $command?->send();
  }, 10, 1);
}
```

- [ ] **Step 4: Create `IntegrationListener.php`**

```php
<?php

namespace TangibleDDD\Application\EventHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * A stateless automation policy: "whenever [fact], then [intention]."
 *
 * The whole job is get_command() — fact in, intention out, null = not my
 * business. All work belongs in the command's handler (audit, retry,
 * causation); a listener only translates. Auto-wired by namespace convention
 * \Application\IntegrationListeners\ (eager boot constructs via the container,
 * so ctor injection is available to subclasses that need it — the happy path
 * needs nothing).
 */
abstract class IntegrationListener {

  /** @return class-string<IIntegrationEvent> */
  abstract protected function get_event_class(): string;

  /** Fact in, intention out. Null = no reaction. */
  abstract protected function get_command(IIntegrationEvent $event): ?ICommand;

  public function __construct() {
    \TangibleDDD\WordPress\integration_listener(
      static::get_event_class(),
      fn(IIntegrationEvent $event) => $this->get_command($event)
    );
  }
}
```

- [ ] **Step 5: Extend the eager-boot walk in `ddd-wordpress/hooks.php`**

In `register_event_handlers()`, change the namespace match line:

```php
foreach ($container->getServiceIds() as $id) {
  $is_handler  = str_contains($id, '\\Application\\EventHandlers\\');
  $is_listener = str_contains($id, '\\Application\\IntegrationListeners\\');
  if (!$is_handler && !$is_listener) continue;
  if (!class_exists($id)) continue;
  // ... existing try/get/catch unchanged
```

Update the function docblock to mention both namespaces.

- [ ] **Step 6: Deprecate `AsyncWordPressActionHandler`**

Prepend to its class docblock:

```php
/**
 * @deprecated 0.2.0 — the "async domain handler" is a category error (the AS hop
 * is another TIME, not another thread; serialization forces params into
 * record-land regardless of intent). Decompose into an IntegrationListener
 * (the policy) + a Command (the work, under command_audit). Removal: 0.3.0.
 *
```

- [ ] **Step 7: Verify pass + full suite + commit**

```bash
vendor/bin/phpunit --filter IntegrationListenerTest && vendor/bin/phpunit
git add -A ddd-src ddd-wordpress tests
git commit -m "feat(listeners): IntegrationListener + integration_listener() ceremony; deprecate AsyncWordPressActionHandler"
```

---

### Task 7: `IAwaitMechanism` + `AwaitEvent` refactor + persistence

**Files:**
- Create: `ddd-src/Application/Process/IAwaitMechanism.php`
- Modify: `ddd-src/Application/Process/AwaitEvent.php`, `ddd-src/Application/Process/Result.php`, `ddd-src/Application/Process/LongProcess.php`, `ddd-src/Infra/Persistence/ProcessRepository.php`, `ddd-wordpress/tables.php`
- Test: `tests/Unit/Process/AwaitEventMechanismTest.php`

**Interfaces:**
- Consumes: `IIntegrationEvent` (Task 3).
- Produces (used by Tasks 8–11):

```php
interface IAwaitMechanism {
  /** SQL prefilter — goes in the waiting_for column. */
  public function event_class(): string;
  /** Routing: is this event for THIS process? */
  public function accepts(IIntegrationEvent $event): bool;
  /** Record an arrival — immutable, returns new instance. */
  public function accumulate(IIntegrationEvent $event): static;
  /** Structural satisfaction — everything ARRIVED? Never judges success. */
  public function is_satisfied(): bool;
  /** What the post-await step receives as its 2nd parameter. */
  public function resume_argument(?IIntegrationEvent $last_event): mixed;
  /** Wall-clock seconds; 0 = no alarm. */
  public function timeout_seconds(): int;
  /** 'fail' | 'proceed' — only consulted when the alarm fires. */
  public function on_timeout(): string;
  /** Persistence codec: plain array of scalars. */
  public function to_array(): array;
  public static function from_array(array $data): static;
}
```

  - `AwaitEvent implements IAwaitMechanism` — 1-of-1: `accepts()` = the criteria match (moved from the runner), `accumulate()` returns `$this`, `is_satisfied()` = true, `resume_argument($last)` = `$last`, `timeout_seconds()` = 0.
  - `Result::__construct(..., public readonly ?IAwaitMechanism $await = null, ...)`.
  - `LongProcess`: `public function await_mechanism(): ?IAwaitMechanism`, `public function update_await(IAwaitMechanism $m): void`, `advance(..., ?IAwaitMechanism $await_mechanism = null)`, `hydrate(..., ?IAwaitMechanism $await_mechanism = null)`.
  - `ProcessRepository`: persists `await_mechanism` as `{"_class": FQCN, "_data": to_array()}` JSON; on hydrate, if column NULL but `waiting_for` set → reconstruct `new AwaitEvent($row->waiting_for, json_decode($row->match_criteria, true) ?? [])` (legacy fallback).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Process/AwaitEventMechanismTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Tests\Fakes\FakeIntegrationEvent;

class AwaitEventMechanismTest extends TestCase {

  public function test_implements_mechanism(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);
    $this->assertInstanceOf(IAwaitMechanism::class, $m);
    $this->assertSame(FakeIntegrationEvent::class, $m->event_class());
  }

  public function test_accepts_matches_criteria_strictly(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);
    $this->assertTrue($m->accepts(new FakeIntegrationEvent(entity_id: 42)));
    $this->assertFalse($m->accepts(new FakeIntegrationEvent(entity_id: 43)));
    $this->assertFalse($m->accepts(new FakeIntegrationEvent()));   // 1 !== 42
  }

  public function test_one_of_one_semantics(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class);
    $e = new FakeIntegrationEvent(entity_id: 42);
    $this->assertTrue($m->accumulate($e)->is_satisfied());
    $this->assertSame($e, $m->resume_argument($e));
    $this->assertSame(0, $m->timeout_seconds());
  }

  public function test_persistence_round_trip(): void {
    $m = new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42]);
    $back = AwaitEvent::from_array($m->to_array());
    $this->assertSame($m->event_class(), $back->event_class());
    $this->assertTrue($back->accepts(new FakeIntegrationEvent(entity_id: 42)));
  }
}
```

- [ ] **Step 2: Verify failure** — interface not found.

- [ ] **Step 3: Create `IAwaitMechanism.php`** with the exact interface from the Produces block above (namespace `TangibleDDD\Application\Process`, `use TangibleDDD\Domain\Events\IIntegrationEvent;`, docblock: "Strategy VO for process suspension. The framework counts arrivals (structural); the coordinator judges outcomes (post-await step). Must serialize to plain scalars — snapshot-resume cannot hold closures.").

- [ ] **Step 4: Refactor `AwaitEvent.php`**

```php
<?php

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * 1-of-1 await: suspend until the first event of $event_class whose public
 * properties strictly match $match_criteria. resume_argument() is the event
 * itself, so 2-param steps keep their existing signature.
 *
 * Any awaited event class must be registered for wake-up — declare it with
 * #[Awaits(EventClass::class)] on the process class.
 */
final class AwaitEvent implements IAwaitMechanism {

  /** @var class-string<IIntegrationEvent> */
  public readonly string $event_class;

  public function __construct(
    string $event_class,
    /** Criteria to match against event properties */
    public readonly array $match_criteria = [],
  ) {
    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new InvalidArgumentException(
        "AwaitEvent expects an IIntegrationEvent class, got: $event_class"
      );
    }
    $this->event_class = $event_class;
  }

  public function event_class(): string { return $this->event_class; }

  public function accepts(IIntegrationEvent $event): bool {
    if (!$event instanceof $this->event_class) {
      return false;
    }
    foreach ($this->match_criteria as $key => $expected) {
      if (!property_exists($event, $key) || $event->$key !== $expected) {
        return false;
      }
    }
    return true;
  }

  public function accumulate(IIntegrationEvent $event): static { return $this; }
  public function is_satisfied(): bool { return true; }
  public function resume_argument(?IIntegrationEvent $last_event): mixed { return $last_event; }
  public function timeout_seconds(): int { return 0; }
  public function on_timeout(): string { return AwaitAll::TIMEOUT_FAIL; }

  public function to_array(): array {
    return ['event_class' => $this->event_class, 'match_criteria' => $this->match_criteria];
  }

  public static function from_array(array $data): static {
    return new static($data['event_class'], $data['match_criteria'] ?? []);
  }
}
```

NOTE: `on_timeout()` references `AwaitAll::TIMEOUT_FAIL` which lands in Task 8. To keep THIS task compiling, use the literal `'fail'` here and switch to the constant in Task 8's cleanup step.

- [ ] **Step 5: Widen `Result`** — change the `await` property to `public readonly ?IAwaitMechanism $await = null` (docblock: "Suspend and wait per this mechanism"). `should_suspend()` unchanged.

- [ ] **Step 6: `LongProcess` state** — add below the `match_criteria` property:

```php
protected ?IAwaitMechanism $await_mechanism = null;

public function await_mechanism(): ?IAwaitMechanism {
  return $this->await_mechanism;
}

/** Persist accumulated arrivals without advancing (partial fan-in). */
public function update_await(IAwaitMechanism $mechanism): void {
  $this->await_mechanism = $mechanism;
  $this->updated_at = new DateTimeImmutable();
}
```

Add `?IAwaitMechanism $await_mechanism = null` as the last parameter of BOTH `advance()` and `hydrate()`, assigning `$this->await_mechanism = $await_mechanism;` in each. (In `advance()`, note: like `waiting_for`, it resets to null unless passed.)

- [ ] **Step 7: `ProcessRepository` persistence + legacy fallback**

In `save()`, add to `$row` after `'match_criteria'`:

```php
'await_mechanism' => $process->await_mechanism()
  ? wp_json_encode([
      '_class' => get_class($process->await_mechanism()),
      '_data'  => $process->await_mechanism()->to_array(),
    ])
  : null,
```

In `hydrate_from_row()`, before the `$process->hydrate(...)` call:

```php
$mechanism = null;
if (!empty($row->await_mechanism)) {
  $decoded = json_decode($row->await_mechanism, true);
  $mclass = $decoded['_class'] ?? null;
  if ($mclass && is_a($mclass, \TangibleDDD\Application\Process\IAwaitMechanism::class, true)) {
    $mechanism = $mclass::from_array($decoded['_data'] ?? []);
  }
} elseif ($row->waiting_for && $row->status === 'suspended') {
  // Legacy row from before 0.2.0 — reconstruct the 1-of-1 mechanism.
  $mechanism = new \TangibleDDD\Application\Process\AwaitEvent(
    $row->waiting_for,
    $row->match_criteria ? (json_decode($row->match_criteria, true) ?? []) : []
  );
}
```

and pass `await_mechanism: $mechanism` to `hydrate()`. Guard the column read for pre-migration rows: use `$row->await_mechanism ?? null` semantics (`!empty($row->await_mechanism)` already handles a missing property only if you use `property_exists`; write `!empty($row->await_mechanism ?? null)`).

- [ ] **Step 8: Schema** — in `ddd-wordpress/tables.php`, in the `long_processes` CREATE TABLE, after `match_criteria JSON NULL,` add:

```sql
    await_mechanism JSON NULL,
```

(dbDelta adds the column on existing installs — additive.)

- [ ] **Step 9: Verify pass + full suite + commit**

Existing runner tests must still pass — nothing consumes the new state yet.

```bash
vendor/bin/phpunit --filter AwaitEventMechanismTest && vendor/bin/phpunit
git add -A ddd-src ddd-wordpress tests
git commit -m "feat(process): IAwaitMechanism strategy — AwaitEvent refactor, Result widening, mechanism persistence + legacy fallback"
```

---

### Task 8: `AwaitAll`

**Files:**
- Create: `ddd-src/Application/Process/AwaitAll.php`
- Modify: `ddd-src/Application/Process/AwaitEvent.php` (swap `'fail'` literal → `AwaitAll::TIMEOUT_FAIL`)
- Create: `tests/Fakes/FakeGatherProcess.php` (also used by Tasks 9–11)
- Test: `tests/Unit/Process/AwaitAllTest.php`

**Interfaces:**
- Consumes: `IAwaitMechanism` (Task 7), `FakeResolvedEvent` (Task 2).
- Produces:

```php
final class AwaitAll implements IAwaitMechanism {
  public const TIMEOUT_FAIL = 'fail';
  public const TIMEOUT_PROCEED = 'proceed';
  public function __construct(
    string $event_class,                 // must be IIntegrationEvent — ctor guard
    array $expected,                     // keys the saga minted (its ledger)
    array $key_by,                       // [class-string, method] static extractor — ctor guard is_callable
    int $timeout_seconds,                // REQUIRED, > 0 enforced
    string $on_timeout = self::TIMEOUT_FAIL,
    array $gathered = [],
  )
  public function gathered(): array
  public function expected(): array
  public function missing(): array      // expected minus gathered (for coordinators + timeout diagnostics)
}
```

  - `FakeGatherProcess extends LongProcess` — ctor `(public readonly array $request_ids = [1, 2, 3])`; step `dispatch()` returns `Result(payload: new FakePayload('dispatched', 1), await: new AwaitAll(FakeResolvedEvent::class, $this->request_ids, [self::class, 'resolution_key'], timeout_seconds: 3600, on_timeout: AwaitAll::TIMEOUT_PROCEED))`; step `evaluate(?FakePayload $payload, AwaitAll $gather)` records `$gather` into a public prop and returns `new Result()`; `public static function resolution_key(FakeResolvedEvent $e): int { return $e->request_id; }`; public array `$executed_steps` like `FakeSuspendingProcess`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Process/AwaitAllTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AwaitAllTest extends TestCase {

  private function mechanism(array $expected = [1, 2, 3]): AwaitAll {
    return new AwaitAll(
      event_class: FakeResolvedEvent::class,
      expected: $expected,
      key_by: [FakeGatherProcess::class, 'resolution_key'],
      timeout_seconds: 3600,
    );
  }

  private function event(int $id): FakeResolvedEvent {
    return new FakeResolvedEvent($id, FakeOutcome::Accepted, new \DateTimeImmutable());
  }

  public function test_accepts_only_expected_keys(): void {
    $m = $this->mechanism();
    $this->assertTrue($m->accepts($this->event(2)));
    $this->assertFalse($m->accepts($this->event(99)));
  }

  public function test_accumulate_until_satisfied(): void {
    $m = $this->mechanism([1, 2]);
    $m = $m->accumulate($this->event(1));
    $this->assertFalse($m->is_satisfied());
    $this->assertSame([2], $m->missing());
    $m = $m->accumulate($this->event(2));
    $this->assertTrue($m->is_satisfied());
  }

  public function test_duplicate_redelivery_is_idempotent(): void {
    $m = $this->mechanism([1, 2])->accumulate($this->event(1));
    $this->assertFalse($m->accepts($this->event(1)));   // already gathered
    $this->assertFalse($m->accumulate($this->event(1))->is_satisfied());
  }

  public function test_resume_argument_is_the_mechanism(): void {
    $m = $this->mechanism();
    $this->assertSame($m, $m->resume_argument($this->event(1)));
  }

  public function test_timeout_is_required_positive(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AwaitAll(FakeResolvedEvent::class, [1], [FakeGatherProcess::class, 'resolution_key'], timeout_seconds: 0);
  }

  public function test_persistence_round_trip_preserves_gathered(): void {
    $m = $this->mechanism([1, 2])->accumulate($this->event(1));
    $back = AwaitAll::from_array($m->to_array());
    $this->assertSame([1], $back->gathered());
    $this->assertFalse($back->accepts($this->event(1)));
    $this->assertTrue($back->accepts($this->event(2)));
  }

  public function test_from_array_rejects_stale_extractor(): void {
    $data = $this->mechanism()->to_array();
    $data['key_by'] = ['NoSuchClass', 'nope'];
    $this->expectException(\InvalidArgumentException::class);
    AwaitAll::from_array($data);
  }
}
```

- [ ] **Step 2: Verify failure** — class not found.

- [ ] **Step 3: Implement `AwaitAll.php`**

```php
<?php

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Fan-in await (AND-join): suspend until an event of $event_class has arrived
 * for EVERY key in $expected. Routing is set-membership of the event's key —
 * extracted by a STATIC METHOD ON THE PROCESS ($key_by, announced per-await) —
 * against ids the saga itself minted. The framework counts arrivals; each
 * entity owns its own outcome; the coordinator's post-await step judges the
 * group (resume_argument() hands it this mechanism: gathered vs expected).
 *
 * timeout_seconds is REQUIRED (pure wall clock; every serious join has one).
 * On fire: TIMEOUT_FAIL → compensation; TIMEOUT_PROCEED → resume with the
 * partial mechanism.
 */
final class AwaitAll implements IAwaitMechanism {

  public const TIMEOUT_FAIL = 'fail';
  public const TIMEOUT_PROCEED = 'proceed';

  /** @var class-string<IIntegrationEvent> */
  public readonly string $event_class;
  /** @var array{class-string, string} */
  public readonly array $key_by;

  public function __construct(
    string $event_class,
    public readonly array $expected,
    array $key_by,
    public readonly int $timeout_seconds,
    public readonly string $on_timeout = self::TIMEOUT_FAIL,
    public readonly array $gathered = [],
  ) {
    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new InvalidArgumentException("AwaitAll expects an IIntegrationEvent class, got: $event_class");
    }
    if ($timeout_seconds <= 0) {
      throw new InvalidArgumentException('AwaitAll requires a positive timeout_seconds — unbounded joins wedge sagas.');
    }
    if (count($key_by) !== 2 || !is_callable($key_by)) {
      throw new InvalidArgumentException('AwaitAll key_by must be a callable [class, static method] pair.');
    }
    if (!in_array($on_timeout, [self::TIMEOUT_FAIL, self::TIMEOUT_PROCEED], true)) {
      throw new InvalidArgumentException("Unknown on_timeout policy: $on_timeout");
    }
    $this->event_class = $event_class;
    $this->key_by = array_values($key_by);
  }

  public function event_class(): string { return $this->event_class; }

  public function accepts(IIntegrationEvent $event): bool {
    if (!$event instanceof $this->event_class) {
      return false;
    }
    $key = ($this->key_by)($event);
    return in_array($key, $this->expected, true)
        && !in_array($key, $this->gathered, true);
  }

  public function accumulate(IIntegrationEvent $event): static {
    $key = ($this->key_by)($event);
    if (in_array($key, $this->gathered, true)) {
      return $this;
    }
    return new static(
      $this->event_class,
      $this->expected,
      $this->key_by,
      $this->timeout_seconds,
      $this->on_timeout,
      [...$this->gathered, $key],
    );
  }

  public function is_satisfied(): bool {
    return count($this->gathered) >= count($this->expected);
  }

  /** The coordinator sees gathered vs expected — partial detection on timeout. */
  public function resume_argument(?IIntegrationEvent $last_event): mixed { return $this; }

  public function timeout_seconds(): int { return $this->timeout_seconds; }
  public function on_timeout(): string { return $this->on_timeout; }

  public function gathered(): array { return $this->gathered; }
  public function expected(): array { return $this->expected; }
  public function missing(): array { return array_values(array_diff($this->expected, $this->gathered)); }

  public function to_array(): array {
    return [
      'event_class' => $this->event_class,
      'expected' => $this->expected,
      'key_by' => $this->key_by,
      'timeout_seconds' => $this->timeout_seconds,
      'on_timeout' => $this->on_timeout,
      'gathered' => $this->gathered,
    ];
  }

  public static function from_array(array $data): static {
    return new static(
      $data['event_class'],
      $data['expected'] ?? [],
      $data['key_by'] ?? [],
      (int) ($data['timeout_seconds'] ?? 0),
      $data['on_timeout'] ?? self::TIMEOUT_FAIL,
      $data['gathered'] ?? [],
    );
  }
}
```

- [ ] **Step 4: Create `tests/Fakes/FakeGatherProcess.php`** per the Produces block:

```php
<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

class FakeGatherProcess extends LongProcess {
  public array $executed_steps = [];
  public ?AwaitAll $gather_seen = null;

  public function __construct(public readonly array $request_ids = [1, 2, 3]) {
    parent::__construct(null);
  }

  protected function dispatch(): Result {
    $this->executed_steps[] = 'dispatch';
    return new Result(
      payload: new FakePayload('dispatched', 1),
      await: new AwaitAll(
        event_class: FakeResolvedEvent::class,
        expected: $this->request_ids,
        key_by: [self::class, 'resolution_key'],
        timeout_seconds: 3600,
        on_timeout: AwaitAll::TIMEOUT_PROCEED,
      ),
    );
  }

  protected function evaluate(?FakePayload $payload, AwaitAll $gather): Result {
    $this->executed_steps[] = 'evaluate';
    $this->gather_seen = $gather;
    return new Result(payload: new FakePayload('evaluated', 2));
  }

  public static function resolution_key(FakeResolvedEvent $e): int {
    return $e->request_id;
  }
}
```

(Match `FakePayload`'s real ctor — check `tests/Fakes/FakePayload.php`; if its ctor differs from `('label', int)`, adapt these two literals, do not change the fake.)

Also swap the `'fail'` literal in `AwaitEvent::on_timeout()` for `AwaitAll::TIMEOUT_FAIL`.

- [ ] **Step 5: Verify pass + full suite + commit**

```bash
vendor/bin/phpunit --filter AwaitAllTest && vendor/bin/phpunit
git add -A ddd-src tests
git commit -m "feat(process): AwaitAll fan-in mechanism — key_by static extractor, mandatory timeout, idempotent accumulation"
```

---

### Task 9: `ProcessRunner` surgery — mechanism-driven resume

**Files:**
- Create: `ddd-src/Application/Process/AwaitedEventNotRegistered.php`
- Modify: `ddd-src/Application/Process/ProcessRunner.php`
- Modify: `tests/wp-stubs.php` (add `$wpdb` lock passthrough note only — locks go through `$wpdb->get_var`, already stubbed)
- Test: `tests/Unit/Process/ProcessRunnerAwaitAllTest.php`

**Interfaces:**
- Consumes: `IAwaitMechanism`/`AwaitEvent`/`AwaitAll` (Tasks 7–8), `TransportEnvelope` (Task 5), `LongProcess::{await_mechanism,update_await}` (Task 7).
- Produces: runner behavior relied on by Tasks 10–11:
  - `register_event()` callback: unwrap envelope → restore context → `from_payload` → `stamp_journey` → `resume_on_event($event)`.
  - `resume_on_event()`: per candidate — `accepts()` → GET_LOCK → `accumulate()` → not satisfied ⇒ `update_await` + save + stay suspended; satisfied ⇒ `advance_step` + resume with `resume_argument()`.
  - `suspend_for_event()`: throws `AwaitedEventNotRegistered` if the mechanism's class has no registered hook; persists mechanism; schedules alarm when `timeout_seconds() > 0`.
  - `AwaitedEventNotRegistered extends \LogicException` — ctor `(string $event_class, string $process_class)`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Process/ProcessRunnerAwaitAllTest.php` — mirror the setUp pattern of the existing `tests/Unit/Process/ProcessRunnerTest.php` (same fake repo + config wiring; READ IT FIRST and copy its setUp verbatim, adjusting only the process class):

```php
<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitedEventNotRegistered;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class ProcessRunnerAwaitAllTest extends TestCase {

  // setUp: copy from ProcessRunnerTest — $this->repo (fake), $this->runner (real ProcessRunner)

  private function event(int $id): FakeResolvedEvent {
    return new FakeResolvedEvent($id, FakeOutcome::Accepted, new \DateTimeImmutable());
  }

  public function test_suspend_requires_registered_event(): void {
    $process = new FakeGatherProcess([1]);
    $this->expectException(AwaitedEventNotRegistered::class);
    $this->runner->start($process);   // no register_event() call made
  }

  public function test_partial_arrival_accumulates_and_stays_suspended(): void {
    $this->runner->register_event(FakeResolvedEvent::class);
    $process = new FakeGatherProcess([1, 2]);
    $this->runner->start($process);
    $this->assertSame('suspended', $process->status());

    $this->runner->resume_on_event($this->event(1));

    $saved = $this->repo->find($process->get_id());
    $this->assertSame('suspended', $saved->status());
    $this->assertSame([1], $saved->await_mechanism()->gathered());
    $this->assertNotContains('evaluate', $process->executed_steps);
  }

  public function test_final_arrival_satisfies_and_resumes_with_mechanism(): void {
    $this->runner->register_event(FakeResolvedEvent::class);
    $process = new FakeGatherProcess([1, 2]);
    $this->runner->start($process);

    $this->runner->resume_on_event($this->event(1));
    $this->runner->resume_on_event($this->event(2));

    $saved = $this->repo->find($process->get_id());
    $this->assertSame('completed', $saved->status());
    $this->assertInstanceOf(AwaitAll::class, $saved->gather_seen ?? $process->gather_seen);
    $this->assertTrue(($saved->gather_seen ?? $process->gather_seen)->is_satisfied());
  }

  public function test_two_sagas_disjoint_keys_route_correctly(): void {
    $this->runner->register_event(FakeResolvedEvent::class);
    $a = new FakeGatherProcess([1]);
    $b = new FakeGatherProcess([2]);
    $this->runner->start($a);
    $this->runner->start($b);

    $this->runner->resume_on_event($this->event(2));

    $this->assertSame('suspended', $this->repo->find($a->get_id())->status());
    $this->assertSame('completed', $this->repo->find($b->get_id())->status());
  }

  public function test_await_event_behavior_unchanged(): void {
    // FakeSuspendingProcess round-trip — identical to the existing resume test:
    // start, fire matching FakeIntegrationEvent through resume_on_event, assert
    // after_action ran and received the EVENT (not a mechanism).
    $this->runner->register_event(\TangibleDDD\Tests\Fakes\FakeIntegrationEvent::class);
    $p = new \TangibleDDD\Tests\Fakes\FakeSuspendingProcess();
    $this->runner->start($p);
    $this->runner->resume_on_event(new \TangibleDDD\Tests\Fakes\FakeIntegrationEvent(entity_id: 42));
    $this->assertSame('completed', $this->repo->find($p->get_id())->status());
  }
}
```

NOTE for the implementer: the fake repo in the existing test returns the SAME instance from `find()` (in-memory). If assertions on `$saved->gather_seen` fail because hydration builds a fresh instance, assert on `$process->gather_seen` — the note in the test shows both.

- [ ] **Step 2: Verify failure** — `AwaitedEventNotRegistered` not found; resume advances unconditionally.

- [ ] **Step 3: Create `AwaitedEventNotRegistered.php`**

```php
<?php

namespace TangibleDDD\Application\Process;

final class AwaitedEventNotRegistered extends \LogicException {
  public function __construct(string $event_class, string $process_class) {
    parent::__construct(
      "$process_class suspends on $event_class, but no wake-up hook is registered for it. " .
      "Declare it: #[Awaits($event_class::class)] on the process class (or the ddd.long_process " .
      "tag's awaits: list). Without registration the saga would sleep forever."
    );
  }
}
```

- [ ] **Step 4: Surgery on `ProcessRunner.php`**

4a. Replace the transient `$resume_event` property with `private mixed $resume_argument = null;` (keep the name distinct — several sites reference it).

4b. `register_event()` — replace the `add_action` callback:

```php
add_action(
  $event_class::integration_action(),
  function (array $payload) use ($event_class) {
    $envelope = \TangibleDDD\Application\Events\TransportEnvelope::unwrap($payload);
    $envelope->restore_context();

    $event = $event_class::from_payload($envelope->payload);
    if ($envelope->event_id !== null) {
      $event->stamp_journey((string) $envelope->correlation_id, $envelope->event_id);
    }

    $this->resume_on_event($event);
  },
  99 // Late priority - run after main handlers
);
```

4c. `resume_on_event()` — full replacement:

```php
public function resume_on_event(IIntegrationEvent $event): void {
  $event_class = get_class($event);
  $waiting = $this->repository->find_waiting_for($event_class);

  foreach ($waiting as $process) {
    $mechanism = $process->await_mechanism();
    if ($mechanism === null || !$mechanism->accepts($event)) {
      continue;
    }

    $this->with_process_lock($process->get_id(), function () use ($process, $event, $mechanism) {
      $updated = $mechanism->accumulate($event);

      if (!$updated->is_satisfied()) {
        // Partial arrival: persist the tally, stay suspended.
        $process->update_await($updated);
        $this->repository->save($process);
        return;
      }

      CorrelationContext::with($process->correlation_id(), function () use ($process, $event, $updated) {
        $process->advance_step();
        $this->resume_argument = $updated->resume_argument($event);
        $process->advance(status: 'running', payload: $process->payload());
        $this->repository->save($process);

        $this->run($process);

        $this->resume_argument = null;
      });
    });

    // Only resume first accepting process per event (key sets are disjoint by construction).
    return;
  }
}
```

4d. Add the lock helper (MySQL named lock; two AS workers racing accumulate would lose an arrival):

```php
/**
 * Serialize accumulate/save per process via MySQL named lock.
 * Lock timeout → throw so ActionScheduler retries the delivery.
 */
private function with_process_lock(int $process_id, callable $fn): void {
  global $wpdb;
  $name = 'ddd_process_' . $process_id;
  $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));

  if ((string) $acquired === '0') {
    throw new \RuntimeException("Could not acquire process lock $name — delivery will be retried.");
  }

  try {
    $fn();
  } finally {
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
  }
}
```

(The wp-stubs `wpdb::get_var` returns `null` → treated as acquired — deliberate for unit tests.)

4e. `execute_step()` — the 2-param branch becomes:

```php
return $step->invoke($process, $process->payload(), $this->resume_argument);
```

4f. `execute_forward()` — replace `$this->resume_event = null;` with `$this->resume_argument = null;`.

4g. `suspend_for_event()` — full replacement:

```php
private function suspend_for_event(LongProcess $process, Result $result): void {
  $mechanism = $result->await;

  if (!isset($this->registered_events[$mechanism->event_class()])) {
    throw new AwaitedEventNotRegistered($mechanism->event_class(), get_class($process));
  }

  $process->advance(
    status: 'suspended',
    payload: $result->payload,
    waiting_for: $mechanism->event_class(),
    await_mechanism: $mechanism,
  );
  $this->repository->save($process);

  if ($mechanism->timeout_seconds() > 0) {
    as_schedule_single_action(
      time() + $mechanism->timeout_seconds(),
      $this->config->hook('await_timeout'),
      ['process_id' => $process->get_id(), 'step_index' => $process->current_step_index()],
      $this->config->as_group('processes')
    );
  }
}
```

(`match_criteria` is no longer written by the runner — the mechanism carries routing. The column stays for legacy rows only.)

4h. Delete `event_matches_criteria()` (its logic moved into `AwaitEvent::accepts()` in Task 7).

4i. Add `as_schedule_single_action` to `tests/wp-stubs.php` (mirror `as_enqueue_async_action`, recording `['timestamp' => $ts, 'hook' => $hook, 'args' => $args, 'group' => $group]` into `$_test_scheduled_actions`).

- [ ] **Step 5: Verify + collateral**

Run: `vendor/bin/phpunit --filter ProcessRunnerAwaitAllTest` → PASS.
Run: `vendor/bin/phpunit` — existing `ProcessRunnerTest` suspend/resume tests must pass; they now flow through the mechanism path (legacy `AwaitEvent`: accepts → accumulate(self) → satisfied → advance — identical observable behavior). If a test asserted `match_criteria` persistence, update it to assert the mechanism instead.

- [ ] **Step 6: Commit**

```bash
git add -A ddd-src tests
git commit -m "feat(process)!: mechanism-driven resume — accumulate-until-satisfied, GET_LOCK, envelope unwrap + journey stamp on wake, suspend-time registration guard"
```

---

### Task 10: Wall-clock timeout + `#[Awaits]` boot registration

**Files:**
- Create: `ddd-src/Application/Process/Awaits.php`
- Modify: `ddd-src/Application/Process/ProcessRunner.php` (add `handle_timeout()`), `ddd-wordpress/hooks.php`
- Test: `tests/Unit/Process/AwaitTimeoutTest.php`, `tests/Unit/Process/AwaitsAttributeTest.php`

**Interfaces:**
- Consumes: Tasks 7–9.
- Produces:
  - `#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)] final class Awaits { public function __construct(public readonly string $event_class) {} }`
  - `ProcessRunner::handle_timeout(int $process_id, int $step_index): void` — no-op unless the process is still `suspended` at THAT step_index (stale-timer guard); then `TIMEOUT_FAIL` → `begin_compensation('Await timed out: missing ' . implode(',', missing))` + run; `TIMEOUT_PROCEED` → advance + resume with partial `resume_argument()`.
  - `hooks.php`: `register_processes_from_container` additionally reads `#[Awaits]` via reflection; `register_process_hooks` registers the `await_timeout` action → `runner->handle_timeout(...)`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Process/AwaitTimeoutTest.php` (same setUp pattern as Task 9's test):

```php
<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AwaitTimeoutTest extends TestCase {

  // setUp: as ProcessRunnerAwaitAllTest + $this->runner->register_event(FakeResolvedEvent::class);

  public function test_suspend_schedules_the_alarm(): void {
    global $_test_scheduled_actions;
    $_test_scheduled_actions = [];
    $p = new FakeGatherProcess([1]);
    $this->runner->start($p);

    $alarms = array_filter($_test_scheduled_actions, fn($a) => str_contains($a['hook'], 'await_timeout'));
    $this->assertCount(1, $alarms);
    $alarm = array_values($alarms)[0];
    $this->assertSame($p->get_id(), $alarm['args']['process_id']);
    $this->assertSame($p->current_step_index(), $alarm['args']['step_index']);
  }

  public function test_stale_alarm_is_a_noop(): void {
    $p = new FakeGatherProcess([1]);
    $this->runner->start($p);
    $suspended_index = $p->current_step_index();
    $this->runner->resume_on_event(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));
    $this->assertSame('completed', $this->repo->find($p->get_id())->status());

    $this->runner->handle_timeout($p->get_id(), $suspended_index);   // fires late
    $this->assertSame('completed', $this->repo->find($p->get_id())->status());   // unchanged
  }

  public function test_proceed_policy_resumes_with_partial(): void {
    $p = new FakeGatherProcess([1, 2]);   // on_timeout: PROCEED (fixture default)
    $this->runner->start($p);
    $this->runner->resume_on_event(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable()));

    $this->runner->handle_timeout($p->get_id(), $this->repo->find($p->get_id())->current_step_index());

    $saved = $this->repo->find($p->get_id());
    $this->assertSame('completed', $saved->status());
    $gather = $p->gather_seen;
    $this->assertInstanceOf(AwaitAll::class, $gather);
    $this->assertFalse($gather->is_satisfied());
    $this->assertSame([2], $gather->missing());
  }

  public function test_fail_policy_compensates(): void {
    // FakeGatherFailProcess: identical to FakeGatherProcess but on_timeout: TIMEOUT_FAIL
    // (create in tests/Fakes as a subclass overriding dispatch()).
    $p = new \TangibleDDD\Tests\Fakes\FakeGatherFailProcess([1]);
    $this->runner->start($p);

    $this->runner->handle_timeout($p->get_id(), $this->repo->find($p->get_id())->current_step_index());

    $this->assertSame('failed', $this->repo->find($p->get_id())->status());
  }
}
```

`tests/Unit/Process/AwaitsAttributeTest.php`:

```php
<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class AwaitsAttributeTest extends TestCase {

  public function test_attribute_is_readable_from_class(): void {
    $attrs = (new \ReflectionClass(FakeGatherProcess::class))->getAttributes(Awaits::class);
    $this->assertNotEmpty($attrs);
    $this->assertSame(FakeResolvedEvent::class, $attrs[0]->newInstance()->event_class);
  }
}
```

(Add `#[Awaits(FakeResolvedEvent::class)]` to `FakeGatherProcess` and create `FakeGatherFailProcess` in this task.)

- [ ] **Step 2: Verify failure.**

- [ ] **Step 3: Create `Awaits.php`**

```php
<?php

namespace TangibleDDD\Application\Process;

use Attribute;

/**
 * Boot-time declaration of an event a process suspends on. The runtime await
 * (the mechanism in Result) owns the semantics; this is its static shadow —
 * every request must register the wake-up hook BEFORE the event fires, and
 * hooks can only be laid from static knowledge (PHP request amnesia).
 *
 * #[Awaits(SomeIntegrationEvent::class)]
 * final class MyProcess extends LongProcess { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Awaits {
  public function __construct(
    /** @var class-string<\TangibleDDD\Domain\Events\IIntegrationEvent> */
    public readonly string $event_class
  ) {}
}
```

- [ ] **Step 4: `ProcessRunner::handle_timeout()`**

```php
/**
 * Await-timeout alarm (wall clock — deliberately not pause-aware, see spec §6.3).
 * Stale-timer guard: no-op unless still suspended at the SAME step index.
 */
public function handle_timeout(int $process_id, int $step_index): void {
  $process = $this->repository->find($process_id);

  if ($process === null || $process->status() !== 'suspended') {
    return;
  }
  if ($process->current_step_index() !== $step_index) {
    return; // stale alarm — the saga already woke and moved on
  }

  $mechanism = $process->await_mechanism();
  if ($mechanism === null) {
    return;
  }

  CorrelationContext::with($process->correlation_id(), function () use ($process, $mechanism) {
    if ($mechanism->on_timeout() === AwaitAll::TIMEOUT_PROCEED) {
      $process->advance_step();
      $this->resume_argument = $mechanism->resume_argument(null);
      $process->advance(status: 'running', payload: $process->payload());
      $this->repository->save($process);
      $this->run($process);
      $this->resume_argument = null;
      return;
    }

    // TIMEOUT_FAIL
    $missing = method_exists($mechanism, 'missing') ? implode(', ', $mechanism->missing()) : '';
    $process->begin_compensation('Await timed out' . ($missing !== '' ? " — missing: $missing" : ''));
    $this->repository->save($process);
    $this->run($process);
  });
}
```

- [ ] **Step 5: Wire boot in `ddd-wordpress/hooks.php`**

In `register_process_hooks()`, after the existing `process_continue` registration, add:

```php
add_action($config->hook('await_timeout'), function(int $process_id, int $step_index) use ($config, $di_getter) {
  try {
    $runner = ($di_getter())->get(ProcessRunner::class);
    $runner->handle_timeout($process_id, $step_index);
  } catch (\Throwable $e) {
    error_log(sprintf('[%s-process] Await-timeout handling failed for process %d: %s', $config->prefix(), $process_id, $e->getMessage()));
    throw $e;
  }
}, 10, 2);
```

NOTE: ActionScheduler passes the scheduled args array as named args to the callback — verify how `process_continue` receives its `process_id` in this file and mirror exactly (same calling convention).

In `register_processes_from_container()`, inside the `foreach ($tagged as $class => $tags)` loop, add attribute reading before the yaml-awaits loop:

```php
foreach ((new \ReflectionClass($class))->getAttributes(\TangibleDDD\Application\Process\Awaits::class) as $attr) {
  $runner->register_event($attr->newInstance()->event_class);
}
```

- [ ] **Step 6: Verify pass + full suite + commit**

```bash
vendor/bin/phpunit --filter 'AwaitTimeoutTest|AwaitsAttributeTest' && vendor/bin/phpunit
git add -A ddd-src ddd-wordpress tests
git commit -m "feat(process): wall-clock await timeout (stale guard, fail|proceed policies) + #[Awaits] boot registration"
```

---

### Task 11: End-to-end pipe test + CI trigger

**Files:**
- Create: `tests/Unit/Integration/EventPipelineTest.php` (pure-PHP "integration-shaped" test; lives under tests/Unit so the suite picks it up)
- Create: `tests/Fakes/FakeOutboxRepository.php` (only if no in-memory outbox fake exists — check `tests/Fakes/` and `tests/Unit/Outbox/` first and reuse)
- Modify: `.github/workflows/phpunit.yml`
- Test: itself

**Interfaces:**
- Consumes: everything. This is the test that never existed: raise → router → outbox → processor wrap → AS-stub → hook fires → typed wake.

- [ ] **Step 1: Write the test**

```php
<?php

namespace TangibleDDD\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Infra\Services\WordPressEventDispatcher;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

/**
 * THE test that never existed: an event travels the REAL pipe —
 * raise → router → outbox row → processor wrap (__-keys) → AS(stub) →
 * integration_action fires → runner hydrates, stamps, routes → saga wakes.
 */
class EventPipelineTest extends TestCase {

  public function test_full_pipe_wakes_the_saga(): void {
    global $_test_actions, $_test_scheduled_actions;
    $_test_actions = [];
    $_test_scheduled_actions = [];
    CorrelationContext::reset();
    CorrelationContext::init('pipe-corr');

    // ── wiring (real classes, in-memory persistence) ──
    // runner + repo: same fake-repo wiring as ProcessRunnerTest's setUp
    // outbox: in-memory IOutboxRepository fake (reuse existing if present)
    // publisher: real ActionSchedulerOutboxPublisher (writes to $_test_scheduled_actions)
    // router: real EventRouter(WordPressEventDispatcher, OutboxIntegrationEventBus(outbox fake))
    // [implementer: assemble per existing test fixtures; ~15 lines]

    $runner->register_event(FakeResolvedEvent::class);

    // 1. saga starts, suspends on [1]
    $saga = new FakeGatherProcess([1]);
    $runner->start($saga);
    $this->assertSame('suspended', $saga->status());

    // 2. the fact occurs — raised through the ROUTER like production code
    $router->publish(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00')));

    // 3. relay drains outbox → AS stub captures the wrapped payload
    $processor->process_batch();
    $as_jobs = array_filter($_test_scheduled_actions, fn($a) => $a['hook'] === FakeResolvedEvent::integration_action());
    $this->assertCount(1, $as_jobs);
    $wrapped = array_values($as_jobs)[0]['args'][0];
    $this->assertArrayHasKey('__correlation_id', $wrapped);
    $this->assertArrayHasKey('__event_id', $wrapped);

    // 4. "AS worker" fires the hook — the wake
    do_action(FakeResolvedEvent::integration_action(), $wrapped);

    // 5. saga completed; coordinator saw the satisfied mechanism
    $this->assertSame('completed', $repo->find($saga->get_id())->status());
    $this->assertTrue($saga->gather_seen->is_satisfied());
  }
}
```

The implementer fills the wiring block from existing fixtures: copy `ProcessRunnerTest`'s repo/runner setUp; for the outbox, reuse an existing in-memory `IOutboxRepository` fake if one exists (search `grep -rl "IOutboxRepository" tests/`), else write `tests/Fakes/FakeOutboxRepository.php` implementing only: `write()` (store entry, mint `'ev-' . count` event_id, return it), `fetch_pending()` (return + mark locked), `mark_completed()`, `release_stale_locks()` (no-op), `cancel_duplicates()` (no-op), and pause no-ops if the interface requires them (`set_pause`/`clear_pause`/`is_paused`). Match `OutboxEntry`'s real shape (read `ddd-src/Application/Outbox/OutboxEntry.php`).

- [ ] **Step 2: Run until green** — this test integrates everything; failures here are integration bugs to fix in the task where they belong (do not paper over in the test).

- [ ] **Step 3: CI trigger** — in `.github/workflows/phpunit.yml`:

```yaml
on:
  push:
    branches: [ master, v3-ddd, 'release/**' ]
  pull_request:
    branches: [ master, v3-ddd, 'release/**' ]
```

- [ ] **Step 4: Full suite + commit**

```bash
vendor/bin/phpunit
git add -A tests .github
git commit -m "test(pipeline): end-to-end pipe — raise → outbox → AS → typed wake; CI triggers for release/**"
```

---

## Self-Review Notes (done at plan time)

- Spec coverage: partition (T1,T3), IntegrationBehaviour/codec/journey (T2), self-publisher (T2/T3 fixtures), single door + guard + publish stamp (T4), envelope (T5), listener + ceremony + deprecation (T6), mechanism + persistence + legacy fallback (T7), AwaitAll + key_by (T8), runner surgery + GET_LOCK + registration guard (T9), timeout + #[Awaits] (T10), e2e + CI (T11). Not in scope (per spec): cred migration, SKILL.md rewrite, doctor audit, replay command.
- `OutboxRepository::write()` return value is verified inside Task 4 Step 7 rather than assumed.
- Type consistency: `stamp_journey(string, string)`, `from_payload(array): static`, `IAwaitMechanism` signatures repeated verbatim in Tasks 7–10.
