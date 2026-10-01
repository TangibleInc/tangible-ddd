# Rolling a WordPress site back from tangible-ddd 0.7 to 0.6.x

> **Status: CURRENT FOR 0.7.0 (unreleased).** This runbook covers the WordPress distribution `tangible/ddd`. ddd-symfony and the plain-PHP PDO default have no 0.6 line to roll back to. The register defines the guarantees behind each step in sections 3.6, 5.1, 5.3 and 7.3 ([contract-register.md](../extraction/contract-register.md)), and the rollback fixtures (`tests/Compat/rollback/**`) prove them against 0.6.6, 0.6.5 and 0.6.2.

A rollback here means this: every plugin that bundles `tangible/ddd` 0.7 goes back to a build that bundles a 0.6.x copy, so the newest copy on the site, and therefore the winner, is 0.6.x again. The database is not restored. 0.7 writes only what 0.6 can read on the shared tables, and the steps below deal with the few things 0.6 cannot run.

## What carries over without action

| Left behind by 0.7 | What 0.6 does with it |
|---|---|
| Schema version 8 (new tables `{prefix}_ddd_wakeups`, `{prefix}_ddd_delivery_ledger`, `{prefix}_ddd_relay_pauses`; nullable columns `ignition_key`, `quarantine_reason`, `version`, `start_path` on `long_processes`, and `claim_token` on the outbox) | Ignores it. 0.6 tolerates an installed schema newer than its own `DDD_SCHEMA_VERSION`. Leave the schema in place. Never drop the v8 columns. |
| Outbox rows | Relays them. 0.7 keeps writing status `completed`, so the 0.6 purge and stats still see them. A row 0.7 wrote with a delay has an absolute `scheduled_at` and `delay_seconds = 0`, so 0.6 does not delay it a second time. |
| An outbox row 0.7 had claimed (`claim_token`, `locked_until` and `locked_by` set) | Skips it until `locked_until` passes, then relays it. |
| Pending wakeup intents (process continuations and await timeouts) | Fires them. 0.7 projects every intent to Action Scheduler when it schedules it, on the 0.6 hooks `{prefix}_process_continue` and `{prefix}_await_timeout` with the 0.6 arguments, future-dated to the due time. |
| Failed or exhausted delivery-ledger rows | Ignores them. 0.6 has no ledger. |
| Quarantined processes (status `failed`, `quarantine_reason` set) | Sees an ordinary failed process. |
| Ignited processes (`ignition_key` set) | Sees ordinary processes. After the roll-forward, 0.7 also checks `ignited_by_event_id`, so an ignition that 0.6 made in between is not repeated. |

## What 0.6 cannot run

None of the following has a callback under 0.6. If any of it is still pending when the winner switches, Action Scheduler fails the action and the work is lost.

1. **Pending `{prefix}_ddd_redeliver` actions.** These are handler retries of DDD-registered listeners. In 0.7 a WordPress listener gets one attempt by default, as in 0.6. Redeliveries therefore exist only for listeners that opted in with `#[Retries(n)]` or the `{prefix}_ddd_delivery_attempts` option (wave 5), and for process ignition and resume subscribers, which keep 5 attempts. Leftover failed ledger pairs whose redelivery Action Scheduler lost also count.
2. **Pending `{prefix}_ddd_wakeup` actions.** These are the ResumeRetry intents a contended wake schedules.
3. **By-reference integration actions.** A fact whose Action Scheduler arguments would exceed 8000 bytes is scheduled as a pointer to its outbox row (`WpLargeEnvelope`). 0.6 cannot resolve the pointer. This only affects facts that 0.6 could not relay at all, because Action Scheduler refuses arguments that large.

## Before the switch

1. **Look at the operator view.**

   ```sh
   wp ddd ops
   ```

   It lists the relay, delivery, wakeup and process layers of every consumer, with the repairs that apply. Repair or abandon what you can while 0.7 still runs it: `--rearm`, `--abandon`, `--resume-stranded`, `--fail-stranded` (`wp help ddd ops`).

2. **Drain what only 0.7 can run.**

   ```sh
   wp ddd drain --before-rollback                 # every consumer
   wp ddd drain --before-rollback --consumer=tgbl_cred --max-rounds=20
   ```

   Each round does three things. It re-schedules redeliveries that Action Scheduler lost. It re-projects pending intents that have no action (a Timeout or Continue goes back onto its 0.6 hook, which 0.6 fires; a ResumeRetry goes onto `{prefix}_ddd_wakeup`). Then it runs every pending action on `{prefix}_ddd_redeliver` and `{prefix}_ddd_wakeup`, plus every by-reference integration action that is due. Rounds repeat until nothing is left, or until `--max-rounds` (default 10). A redelivery always ends delivered or exhausted, because every attempt spends its budget (5 by default). Each consumer prints a line like `[tgbl_cred] ran 12 actions in 3 rounds; 0 remain`.

   The command **fails while anything remains**. The count covers:

   - pending actions on the two N-only hooks;
   - failed ledger pairs with no redelivery queued;
   - pending intents still without an Action Scheduler action;
   - by-reference integration actions that are due in the future.

   **Do not switch while it fails.** Re-run it, or inspect the leftovers with `wp ddd ops`.

3. **Future-dated by-reference facts.** The drain does not run them early, because the delay belongs to the fact, so they keep `remaining` above zero until they are due. You can wait until they are due and re-run the drain. Or you can accept that those facts will not reach their subscribers under 0.6. Their payload stays in the `{prefix}_integration_outbox` row (`completed`), so it can be announced again after the roll-forward.

4. **Note processes that are waiting at an `#[Async]` step.** These are `scheduled` rows in `{prefix}_long_processes`, visible in the dashboard's process list. They stall under 0.6, as explained below. Also look for processes suspended on an `AwaitAlarm` (query below).

## Switch the winner

Downgrade or deactivate every plugin that bundles 0.7, so that the newest copy left on the site is 0.6.x. Newest-wins applies across all plugins, so a single 0.7 copy anywhere keeps 0.7 the winner. Check which copy won:

```sh
wp eval 'var_dump( Tangible_DDD_Versions::instance()->winner() );'
```

The winner is authoritative. `TANGIBLE_DDD_VERSION` and the dashboard can show another copy's version, because the first copy to define the constant keeps it.

## After the switch: known 0.6 behaviour

- **Processes at an `#[Async]` step stall until the roll-forward.** Every 0.6.x copy has this defect: an `#[Async]` step re-schedules its continuation before it runs, on every continuation, so the step never runs. Exactly one `{prefix}_process_continue` action stays queued, no step runs twice, and nothing is corrupted. 0.7 fixed the defect, and after the roll-forward it completes these processes. A 0.6-written process of this kind completes under 0.7 too.
- **The three 0.6.x bugs are back** for work that 0.6 does after the switch. The fixed hotfix line `hotfix/0.6.7` is prepared but not released, so the rollback target is a release such as 0.6.6.
  - A NULL or failed `GET_LOCK` can run a process step unlocked.
  - `#[StartsOn]` ignition is check-then-insert again, so two concurrent deliveries can ignite twice.
  - Facts that 0.6 writes are delayed twice again (tangible-cred's `EndpointAuthRefresh` and `BehaviourWorkflowReschedule`). Facts that 0.7 wrote keep their single delay.
- **D3 awaits and alarms.** On WordPress, keyed awaits (`AwaitEvent::keyed`, `AwaitAll::keyed`), `AwaitAny` and a dynamic `AwaitAll` are not supported (register section 4, wp `-`), because 0.6 cannot decode them. A WordPress consumer that used them anyway has processes that 0.6 cannot resume. `AwaitAlarm` does run on WordPress, but 0.6 does not know the class: it reads a process suspended on one as having no await, and the rollback fixtures do not cover what its alarm then does. Before switching, list such processes:

  ```sh
  wp db query "SELECT id, process_class FROM $(wp db prefix)<consumer prefix>_long_processes WHERE status = 'suspended' AND await_mechanism LIKE '%AwaitAlarm%'"
  ```

## Roll forward again

1. Restore the 0.7 builds and confirm the winner as above.
2. Nothing migrates twice. Schema v8 is already installed, and the migration ledger skips it. 0.6 left no intent rows for the continuations and timeouts it scheduled, and the stranded scan handles that: it mints no intent while a 0.6-queued action for the process exists.
3. Run one relay tick and check the operator view:

   ```sh
   wp ddd relay --once
   wp ddd ops
   ```

4. Processes that stalled at an `#[Async]` step complete on their next continuation.
