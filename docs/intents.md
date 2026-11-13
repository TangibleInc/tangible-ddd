# Captured intents

> **Status: CURRENT FOR 0.6.15.** How an act contains one of our own entry
> points being re-entered through foreign code, without breaking DDD-M1. The
> decision and its rejected alternatives are in
> [ADR 0019](adr/0019-captured-intents.md).

An act never nests ([DDD-M1](modeling-rules.md#ddd-m1-one-command-one-intent)).
A command sent while an act is open throws `CommandDispatchedInsideCommand`, an
`IncorrectUsage` that `catch (\Exception)` cannot swallow. Captured intents
don't change that. A window is a containment valve, not a licence: a handler
that sends a command itself is still a violation, fixed by restructuring it.

The valve is for our own entry points: hook listeners that run as their own
act when the outside world fires them, and that get re-entered synchronously
through foreign code our act calls. The sender is our code; only the path back
in is foreign. Such a listener is not nested by design, so "restructure your
nested send" does not apply to it. The window contains the send and forces a
recorded decision about it.

## When to use a window

Use `Intents::capture()` when all of these are true:

- the handler must make the foreign call (there is no lower-level API that
  does the work without firing the hook);
- that call fires a hook or callback that re-enters one of our entry points,
  and the entry point sends a command; and
- the handler knows what should happen to that command: it does the same work
  itself, or the work must not happen in this act.

Don't use a window for a send the handler makes itself. If your handler wants
a side effect now, call a domain service in-band; if it wants follow-on work,
record an integration event and let a listener send the command. A window is
not a way around DDD-M1: it contains a send DDD-M1 couldn't prevent, and makes
the act record what it decided about it.

## How it works

```php
use TangibleDDD\Application\Intents\Intents;

$captured = Intents::capture(fn() => $foreign->do_something($id));

$captured->of(SomeCommand::class)->fulfil();   // this act does SomeCommand's work itself, in-band
```

- `Intents::capture($call)` opens a **window** for the current act, runs
  `$call`, closes the window (in a `finally`), and returns the `CapturedIntents`
  sent inside it.
- A command sent inside the window is not run and does not throw. The guard in
  `CorrelationMiddleware` captures it as an **intent**: the command, the trace
  context it was sent under, and its origin. The sender gets `null` back, as
  from any void handler. No act opens for it: no audit row, no transaction.
- The origin is the `file:line` of the sender's `->send()` call, found from
  the innermost `League\Tactician\CommandBus::handle` frame; failing that, the
  `handle()` call itself (the command was handed to the bus directly); with no
  `handle()` frame, the first frame outside `ddd-src/` and
  `vendor/league/tactician`; and `unknown` only after that. The path is
  relative to `ABSPATH` when the file lies under it.
- Only an `ICommand` is captured. Any other message sent on the bus inside a
  window still throws `CommandDispatchedInsideCommand`.
- Windows nest. The innermost open window captures, and each window returns
  only its own intents.
- Process starts are not covered: `ProcessStartedInsideCommand` still throws
  inside a window.

## The decision rule

Every captured intent must be **decided** before the act ends:

- `fulfil()`: the act does the work itself, in-band. It marks the intents
  fulfilled and returns their commands, so the handler can read what was asked
  for. It must not send them: that would be a nested send outside a window,
  and it throws. Call the domain service the command's handler would call.
- `reject(string $reason)`: the act decides against them. The reason is
  required; an empty one is `IncorrectUsage`.
- **defer** (the intent becomes its own act later) is planned, not built.

`of(string $class)` narrows a `CapturedIntents` to the intents whose command is
an instance of `$class`. They are the same intents, not copies, so deciding the
subset decides them. A `CapturedIntents` is also `Countable` and iterable.

Three more filters narrow the same way (same intents, not copies, and chainable):
`undecided()` keeps the intents not yet decided; `where(callable $predicate)`
keeps those for which `$predicate(CapturedIntent $intent)` returns true;
`matching(array $properties)` keeps those whose command has every given public
property `===` its value (a strict comparison; a command without the property is
excluded, not an error). An empty result is an empty set, and rejecting it does
nothing. In a catch: `$captured->undecided()->reject('failed: ' . $e->getMessage());`

Decisions are all-or-none: `fulfil()` or `reject()` on a set that contains an
already decided intent throws `IntentAlreadyDecided` and decides none of them.

`IntentsMiddleware` checks at the end of the act. If any intent is still
undecided, it throws `UndecidedIntents`, naming each command class and its
origin. The check runs inside the transaction and after synchronous
domain-event handlers, so a transactional act rolls back and a window opened
in one of those handlers is checked by the same act. A command that is not
`ITransactionalCommand` has no transaction to roll back: its earlier writes
stay, as with any other `\Error`. A handler's own exception is never replaced
by `UndecidedIntents`.

If `$call` throws, the window still closes, and whatever it captured stays
registered with the act, undecided: nothing returned a `CapturedIntents` to
decide them. A handler that catches the throw and carries on still fails at the
end of the act. Catching the call's failure doesn't make the commands it sent
disappear.

When a failure inside the call should fail only part of the act's work (one
item of a batch), catch it inside the callable instead, so `capture()` returns
the set, decide every intent with a reason that names the failure, then rethrow
or record the failure where it belongs:

```php
$failure = null;
$captured = Intents::capture(function() use (&$failure) {
  try { $content_repo->complete_content($user_id, $course_id, $date); }
  catch( \Throwable $e ) { $failure = $e; }
});
if( $failure ) {
  $captured->reject('course completion failed: ' . $failure->getMessage());
  throw $failure;   // the item's own failure handling takes it from here
}
```

## Example: Cred's bulk issue

Cred's bulk issue completes a LearnDash course for each item of a workflow.
Completing makes LearnDash fire `learndash_course_completed` synchronously, and
Cred's own completion hook (`tgbl_cred_hook_ld_course_completed_fn`), an entry
point that normally runs as its own act, is re-entered and sends
`IssueCompletionEarningsCommand`. Inside the bulk run that send is a
nested command and throws `CommandDispatchedInsideCommand`. Yet it asks for
exactly the work the item is there to do: issue the earning for that completion.
So the item does that work itself, in-band, with its own origin and date, and
the captured command is **fulfilled** by it. Unhooking the listener around the
call would be invisible in the audit and would drop anything else the hook
sends. Instead:

```php
$captured = Intents::capture(
  fn() => $content_repo->complete_content($user_id, $course_id, $date)
);

// the work the hook's command asked for, done in-band
$issuance_service->issue_for_completion($user_id, $course_id, $date, origin: EarningOrigin::bulk_issue);
$captured->of(IssueCompletionEarningsCommand::class)->fulfil();
// anything else captured and left undecided fails the act at its end
```

Fulfilling is the right decision whenever the act does what the command asked,
even if it does it differently (here, with a different origin). Rejecting is for
work the act decides should not happen.

Cred's real handler narrows further: it fulfils only the command for the item's
own user and course. A completion the item triggered for someone else (a plugin
that completes a second course when the first is done) is fulfilled too, and
issued as the ordinary completion it is. Anything else it captured is rejected
with a reason and recorded on the item. If a later LearnDash or Cred version
started sending a second command from the same hook and nothing decided it, the
act (every item it ran, not only this one) would fail with `UndecidedIntents`
naming it, instead of dropping it silently.

Every command sent inside the window is captured, whoever sends it, not only
the one the act is thinking of. Other plugins listen on the same hook:
tangible-datastream's LearnDash event source hooks `learndash_course_completed`
and sends `RecordCapturedEventCommand`, so on a site where it is active the
example above fails with `UndecidedIntents` naming that command. The act must
decide each intent: reject what it doesn't own, with a reason (here,
datastream's capture of the event is lost, as it is today when the guard
throws), and don't blanket-reject with `of(ICommand::class)`, which would also
swallow whatever a later version starts sending. Commands the act neither does
nor owns, but which should still happen, are the first pressure toward
**defer**.

## Errors

All are `IncorrectUsage` (`\Error`), in `TangibleDDD\Application\Intents`.

| Class | When |
| --- | --- |
| `CaptureOutsideAct` | `Intents::capture()` with no act open |
| `IntentsMiddlewareMissing` | `Intents::capture()` in an act whose command bus lacks `IntentsMiddleware` |
| `IntentsMiddlewareMisplaced` | `IntentsMiddleware` runs with no act open: it is not inside `CorrelationMiddleware` on its bus |
| `UndecidedIntents` | an act ends with a captured intent undecided |
| `IntentAlreadyDecided` | deciding an intent a second time |

## Wiring

`IntentsMiddleware` sits on every command bus (required on any bus whose
handlers call `Intents::capture()`), immediately after
`TransactionMiddleware` and before `DomainEventsPublishMiddleware`. It is never
on the query bus: queries are not acts. The framework's own buses and the
`wp ddd init` scaffold carry it; a consumer that defines its own command bus
adds it ([wiring a consumer](wiring-a-consumer.md#command-and-query-buses)).
A bus without it still runs every command as before; only `Intents::capture()`
refuses, with `IntentsMiddlewareMissing`, so a missing wire fails at the first
capture instead of losing intents.

## Audit

The act's `command_audit` row records its decisions in the `intents` column
(schema v8), as a list of:

```json
{ "command": "Tangible\\Cred\\Application\\Commands\\IssueCompletionEarningsCommand",
  "origin": "wp-content/plugins/tangible-cred/includes/hooks/completion.php:42",
  "decision": "fulfilled" },
{ "command": "Tangible\\Datastream\\Application\\Commands\\RecordCapturedEventCommand",
  "origin": "wp-content/plugins/tangible-datastream/src/Application/EventSources/LearnDashEventSource.php:161",
  "decision": "rejected", "reason": "captured during bulk issuance #12; not handled" }
```

`decision` is `fulfilled`, `rejected` or `undecided`; only a rejection has a
`reason`. The origin's path is relative to `ABSPATH` when the file lies under
it, and the absolute path PHP reports otherwise. Undecided intents are
recorded as `"decision": "undecided"` on the failed act's row, so a failure
can be read from the audit alone. The column stays `NULL` for acts without
intents, and a consumer that hasn't migrated to v8 yet keeps writing its
audit rows without it. The captured command has no row of its own.
