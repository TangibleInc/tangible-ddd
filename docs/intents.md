# Captured intents

> **Status: CURRENT FOR 0.6.13.** How a handler calls foreign code that sends
> a command, without breaking DDD-M1. The decision and its rejected
> alternatives are in [ADR 0019](adr/0019-captured-intents.md).

An act never nests ([DDD-M1](modeling-rules.md#ddd-m1-one-command-one-intent)).
A command sent while an act is open throws `CommandDispatchedInsideCommand`, an
`IncorrectUsage` that `catch (\Exception)` cannot swallow. Captured intents
don't change that. A window is not a way to dispatch from a handler: commands
still can't be sent inside commands. It is a valve for when it happens anyway,
because a handler has to call code it doesn't own that sends a command,
usually from a WordPress hook.

Never open a window around your own code. Reach for one only when foreign code
sends through a hook you can't change, and when you own the sending code,
remove the send at its source instead.

## When to use a window

Use `Intents::capture()` when all of these are true:

- the handler must make the foreign call (there is no lower-level API that
  does the work without firing the hook);
- the foreign code sends a command through a hook or callback you don't own,
  or don't want to change for this one caller; and
- the handler knows what should happen to that command: it does the same work
  itself, or the work must not happen in this act.

Don't use a window for your own code. If your handler wants a side effect
now, call a domain service in-band; if it wants follow-on work, record an
integration event and let a listener send the command. A window is not a way
around DDD-M1: it contains a send DDD-M1 couldn't prevent, and makes the act
record what it decided about it.

## How it works

```php
use TangibleDDD\Application\Intents\Intents;

$captured = Intents::capture(fn() => $foreign->do_something($id));

$captured->of(SomeCommand::class)->reject('done in-band by this act');
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
end of the act. Catching a foreign failure doesn't make the commands it sent
disappear.

## Example: Cred's bulk issue

Cred's bulk issue completes a LearnDash course for each item of a workflow.
Completing fires `learndash_course_completed`, and Cred's own completion hook
sends `IssueCompletionEarningsCommand`. Inside the bulk run that send is a
nested command and fails the item, but the bulk run is already issuing that
earning itself, with its own origin and date. Unhooking the listener around the
call would be invisible in the audit and would drop anything else the hook
sends. Instead:

```php
$captured = Intents::capture(
  fn() => $content_repo->complete_content($user_id, $course_id, $date)
);

$captured->of(IssueCompletionEarningsCommand::class)
  ->reject('issued by bulk issuance #' . $issuance_id);
// anything else captured and left undecided fails the act at its end
```

If a later LearnDash or Cred version starts sending a second command from the
same hook, the item fails with `UndecidedIntents` naming it, instead of
dropping it silently.

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

`IntentsMiddleware` sits on every command bus, immediately after
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
  "decision": "rejected", "reason": "issued by bulk issuance #12" }
```

`decision` is `fulfilled`, `rejected` or `undecided`; only a rejection has a
`reason`. The origin's path is relative to `ABSPATH` when the file lies under
it, and the absolute path PHP reports otherwise. Undecided intents are
recorded as `"decision": "undecided"` on the failed act's row, so a failure
can be read from the audit alone. The column stays `NULL` for acts without
intents, and a consumer that hasn't migrated to v8 yet keeps writing its
audit rows without it. The captured command has no row of its own.
