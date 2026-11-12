# Captured intents: deciding a command sent from foreign code inside an act

Status: draft for review, 2026-11-12. Branch `feat/captured-intents`, off master `72479eb` (0.6.12,
untagged).

Before this branch merges, the lasting parts move into an ADR, `GLOSSARY.md`, a concept doc and the
ledger, and this file is deleted (AGENTS.md, "Documentation").

## Why

An act never nests (DDD-M1). `CorrelationMiddleware` enforces it: a command sent while an act is open
throws `CommandDispatchedInsideCommand`, an `IncorrectUsage` (ADR 0018). That is right for our own
code, but a handler sometimes has to call foreign code that sends a command from inside it, through a
WordPress hook the handler doesn't own.

The case that drives this: TCred's bulk issue completes a LearnDash course inside a workflow item.
Completing fires `learndash_course_completed`; TCred's own completion hook sends
`IssueCompletionEarningsCommand`. Inside the bulk run that send is a nested command. Since 0.6.12 it
throws past the hook's `catch (\Exception)` and fails the item, which is correct, but leaves the run no
way to say "I know, I'm issuing this earning myself, with my own origin and date."

Today the only way out is to unhook the listener around the call, which is invisible in the audit and
silently drops whatever else the foreign code sends.

## What changes

A handler can declare a **window** around a foreign call. A command sent inside the window is not run
and does not throw: it is **captured** as an **intent**. The handler then **decides** each captured
intent, and every one must be decided before the act ends. Outside a window, nothing changes.

```php
$captured = Intents::capture(fn() => $content_repo->complete_content($user_id, $course_id, $date));

$captured->of(IssueCompletionEarningsCommand::class)->reject('issued by bulk issuance #' . $id);
// anything else captured and left undecided fails the act at its end
```

## Terms

Existing terms keep their glossary meaning. **Act** is one command execution. **Fact** is a published
integration event, not "any event". The design notes said "fact: an event, in the past tense", but
the glossary already defines both, so this spec uses its definitions.

New:

- **Intent**: a command held as a value instead of being run, together with the trace context it was
  sent under.
- **Window**: the span an act declares, with `Intents::capture()`, around a call into foreign code.
  Inside it, a sent command becomes a captured intent instead of throwing.
- **Decision**: what the act does with a captured intent. In v1 it is **fulfil** (the act carries it
  out in-band, itself) or **reject** (the act decides against it, with a reason). The planned third
  decision, **defer** (it becomes its own act later), is out of v1 (see "Not in v1").

## Design

All new classes are in `ddd-src/Application/Intents/`, namespace `TangibleDDD\Application\Intents`.

### Value classes

```php
class Intent {
  public function __construct(
    public readonly ICommand $command,
    public readonly TraceContext $born_in,   // Correlation::current() at the send: the act's scope
  ) {}
}

final class CapturedIntent extends Intent {
  public readonly string $origin;            // "file:line" of the send(), from debug_backtrace
  // decision state: undecided | fulfilled | rejected (+ reason); set only through CapturedIntents
}
```

`CapturedIntents implements \Countable, \IteratorAggregate`. It is what a window returns:

- `of(string $class): self`: the subset whose command `instanceof $class`. Same intents, not copies.
  Deciding the subset decides them.
- `fulfil(): list<ICommand>`: marks each one fulfilled and returns its command, for the handler to
  carry out in-band (a domain service call, not a send).
- `reject(string $reason): void`: marks each one rejected with the reason. An empty reason is
  `IncorrectUsage`.
- Deciding an intent twice is `IncorrectUsage` (`IntentAlreadyDecided`).

### The window

`final class Intents`, static, the public entry point:

```php
public static function capture(callable $call): CapturedIntents
```

1. If no act is open (`Correlation::peek()?->cause?->kind !== Kind::Act`), throw `IncorrectUsage`
   (`CaptureOutsideAct`). A window only means something inside an act; outside one a send runs
   normally anyway.
2. If the open act isn't watched by `IntentsMiddleware`, throw `IncorrectUsage`
   (`IntentsMiddlewareMissing`). Otherwise a bus without the middleware would silently drop
   undecided intents (see "Wiring").
3. Open a window, run `$call`, and close the window in a `finally`. Return the intents captured in
   that window.

If `$call` throws, the window still closes. Whatever it had captured stays registered with the act,
undecided, because nothing returned a `CapturedIntents` to decide them, and the throw propagates. If
the handler catches the throw and carries on, the act still fails at its end with `UndecidedIntents`.
Catching a foreign failure doesn't make the commands it sent disappear.

Nested windows: a window opened inside another window's `$call` captures what is sent inside it. The
innermost open window wins. Each window returns only its own intents.

`final class IntentWindows` is internal. It holds, per open act (keyed by the act's `command_id`),
the stack of open windows and every intent captured in the act. It is static, like `Correlation`,
with `reset()` for tests and workers. Its methods are used only by `CorrelationMiddleware`,
`IntentsMiddleware` and `Intents`:

- `watch(string $act_id)` / `unwatch(string $act_id)`: called by `IntentsMiddleware`
- `open(string $act_id)` / `close(string $act_id): CapturedIntents`: called by `Intents::capture()`
- `try_capture(object $command, string $origin): bool`: called by the guard
- `undecided(string $act_id): list<CapturedIntent>`
- `record(string $act_id): array`: decisions for the audit

### The guard

The nested-dispatch guard stays in `CorrelationMiddleware::execute()`. It is the one place that sees
the enclosing act before a new one opens. It changes from "throw" to "capture, else throw":

```php
if ($enclosing->cause?->kind === Kind::Act) {
  if (IntentWindows::try_capture($command, $origin)) {
    return null;                       // not run: no act, no audit row, no transaction
  }
  throw new CommandDispatchedInsideCommand(...);   // unchanged message and meaning
}
```

- `try_capture()` captures only when the enclosing act has an open window and the message is an
  `ICommand`. Anything else on the bus inside a window still throws `CommandDispatchedInsideCommand`.
  An intent's contract is a command value, and a foreign message object has no shape we can decide on.
- The sender gets `null` back, which is what a void command handler returns. Foreign code that reads
  a command's return value will see `null`, as it would from any void handler.
- `$origin` is the first frame outside `League\Tactician` and `TangibleDDD\Application`, taken from
  `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)`.

Where master differs from the design notes: the notes had the guard asking `IntentWindows::hold()`
from a separate middleware. On master the guard and the act bracket are one owner,
`CorrelationMiddleware` ("guard + scope + the audit record, one owner"). So the capture branch goes
there, not in a second guard.

### End of act: `IntentsMiddleware`

`final class IntentsMiddleware implements Middleware`, placed between `TransactionMiddleware` and
`DomainEventsPublishMiddleware`:

```text
CorrelationMiddleware
  -> TransactionMiddleware
  -> IntentsMiddleware          (new)
  -> DomainEventsPublishMiddleware
  -> SelfExecutingCommandMiddleware
  -> CommandHandlerMiddleware
```

1. Read the act id from `Correlation::current()->cause->id`. `CorrelationMiddleware` opened the act
   scope outside it, so the id is already there. Then `IntentWindows::watch($act_id)`.
2. `$next($command)`.
3. Then, still inside the transaction: if `IntentWindows::undecided($act_id)` is not empty, throw
   `UndecidedIntents` (extends `TangibleDDD\IncorrectUsage`). Its message lists each intent's command
   class and origin. `TransactionMiddleware` rolls back, so a transactional act leaves nothing behind.
4. `unwatch($act_id)` in a `finally`.

Because it wraps `DomainEventsPublishMiddleware`, the check runs after synchronous domain-event
handlers have drained. A window opened inside one of those handlers is checked by the same act.

**Non-transactional commands** (not `ITransactionalCommand`): the check runs and throws the same way.
There is no transaction, so writes made before the end stay. That matches how any other `\Error`
behaves in such a command. TCred's bulk run is a workflow command whose items save one by one; an
item that leaves an intent undecided is a programming error, and failing the act is what we want.

### Audit

Decisions are recorded with the act. `CorrelationMiddleware`'s `finally` already calls
`command_audit_finalise()`. It also passes `'intents' => IntentWindows::record($command_id)`, a list of:

```json
{ "command": "Tangible\\Cred\\Application\\Commands\\IssueCompletionEarningsCommand",
  "origin": "wp-content/plugins/tangible-cred/includes/hooks/completion.php:42",
  "decision": "rejected", "reason": "issued by bulk issuance #12" }
```

Undecided intents are recorded as `"decision": "undecided"` on the failed act's row, so a failure can
be read from the audit alone. `IntentWindows` forgets the act after finalise.

**Where master differs from the design notes:** the notes said "the act's existing audit JSON, no
migration". On master the audit row has typed JSON columns (`parameters`, `events`, `error`,
`environment`). `events` is a list of `{name, reactions}` that the dashboard's trace island reads as
the act's moments (`trace-island.js`, `TraceTimelinePresenter`). Putting intents in it would show
them as events. **Chosen: a new `intents JSON NULL` column on `command_audit`, schema version 8.**
It's one nullable column, added by the existing per-prefix self-healing migration (`dbDelta` on
`admin_init`). Rows without intents leave it `NULL`.

The `future` branch is already on schema v9 (parked facts). Whichever merges second renumbers.

### Wiring

- `ddd-wordpress/di/services.yaml`: register `IntentsMiddleware`, autowired.
- `ddd-wordpress/di/tactician.yaml`: add it to `League\Tactician\CommandBus` in the position above.
  This is the bus ddd's own self-consumer uses. Not on the query bus: queries are not acts.
- Consumers that define their own command bus (TCred, datastream, notifications-pro) add it in the
  same position. `docs/wiring-a-consumer.md` gets it in the middleware table and the order diagram.
- A bus without it still works as today for every command. Only `Intents::capture()` refuses to run
  (`IntentsMiddlewareMissing`), so a missing wire fails at the first capture, not by losing intents.

### Errors (all `TangibleDDD\IncorrectUsage`, in `ddd-src/Application/Intents/`)

| Class | When |
|---|---|
| `UndecidedIntents` | an act ends with a captured intent undecided |
| `CaptureOutsideAct` | `Intents::capture()` with no act open |
| `IntentsMiddlewareMissing` | `Intents::capture()` in an act whose bus lacks `IntentsMiddleware` |
| `IntentAlreadyDecided` | deciding an intent a second time |

`CommandDispatchedInsideCommand` is unchanged. Its docblock gains a fourth fix: "the outer handler
calls foreign code that sends → wrap the call in `Intents::capture()` and decide what it sent."

## Not in v1

- **`defer()` and `DeferredIntent`.** The planned third decision: the intent becomes its own act later
  (a `DeferredIntent` with `?int $not_before`, carried by an `IntentEnvelope` the way an
  `IntegrationEnvelope` carries a fact, published like the outbox). It needs command serialization,
  which doesn't exist. `CapturedIntents` gets no placeholder method. The decision set is described as
  fulfil, reject and, later, defer, so the rule "every intent is decided" already covers it.
- Process starts inside a window. `ProcessStartedInsideCommand` is a separate guard and is unchanged.
- A Phan rule for `send()` outside a window (DDD-M1's planned static check) stays planned.

## Tests

Unit (`tests/Unit/Intents/`, in the style of `tests/Unit/Correlation/ActBracketTest.php`):

1. A handler's send outside any window throws `CommandDispatchedInsideCommand`, and the exception's
   line is the send's.
2. A send inside a window is captured, not run (its handler is never called). `fulfil()` returns that
   same command.
3. An act that ends with an undecided intent throws `UndecidedIntents` naming the class and origin.
   For a transactional command, the transaction is rolled back.
4. `reject('reason')` lets the act commit. The audit `intents` column records
   `{command, origin, decision: rejected, reason}`.
5. The window's callback throws after capturing; the handler catches it and returns normally. The act
   still fails at its end with `UndecidedIntents`.
6. A synchronous domain-event handler's send (outside a window) throws `CommandDispatchedInsideCommand`
   at its line.
7. With no act open, a send runs normally, exactly as today.
8. `Intents::capture()` with no act throws `CaptureOutsideAct`. On a bus without `IntentsMiddleware` it
   throws `IntentsMiddlewareMissing`.
9. Nested windows: each returns only its own intents; the inner one captures what is sent inside it.
10. `of()` narrows: rejecting one class leaves another class's intent undecided, so the act fails.
11. Deciding twice throws `IntentAlreadyDecided`. `reject('')` throws `IncorrectUsage`.
12. A non-`ICommand` message sent inside a window throws `CommandDispatchedInsideCommand`.

Integration (`tests/Integration/Framework/`, beside `NoNestingE2ETest`): a real bus with a WordPress
action. The handler captures the action, which sends a command, and rejects it. One audit row exists
for the outer act, with the `intents` column set; the inner command has no row; schema v8 is applied.

Existing suites stay green, `NoNestingE2ETest` and `ActBracketTest` in particular.

## Docs that land with the PR

- **ADR 0019**, "A command sent from foreign code inside an act is captured only in a declared window,
  and every capture is decided before the act ends". Rejected alternatives: unhooking listeners around
  the call; silently dropping nested sends; queueing them automatically (that's `defer`, and only by
  decision); intents in the `events` column.
- **`GLOSSARY.md`**: Intent, Window, Decision (with fulfil / reject / defer), in a new "Intents"
  section. _Avoid_: receipt, proposal, held command.
- **Concept doc** `docs/intents.md` (CURRENT): when to use a window, the decision rule, and the TCred
  bulk-issue example. Linked from `docs/README.md`.
- **`docs/modeling-rules.md`**, DDD-M1: "Enforced by" also names `Intents::capture()` as the one
  sanctioned exception.
- **`docs/wiring-a-consumer.md`**: `IntentsMiddleware` in the table and the order diagram.
- **`docs/agents.md`**: one line under DDD-M1.
- **Ledger** (`docs/migration-0.2-to-0.3.md`): the next contiguous version after 0.6.12 (ADR 0009).
  Consumers add `IntentsMiddleware` to their command bus. Schema v8 adds `command_audit.intents`.

## Open

1. Whether 0.6.12 is tagged on its own first (it is merged, untagged) or ships together with this as
   the next version. Recommended: tag 0.6.12 now, so TCred can pin it independently.
