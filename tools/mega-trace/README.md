# DDD Mega Trace

DDD Mega Trace is a development-only WordPress sidecar that produces a timed,
cross-plugin story for TangibleDDDash. It defines module code under the native
LMS, Quiz, Cred, and Datastream namespaces, while every command still runs
through the owning host consumer's config, transaction middleware, outbox,
workers, process store, workflow store, audit table, and dashboard identity.

It does not fabricate dashboard rows. Course activity, scores, registry
acknowledgement, and similar inputs are synthetic; all causal joints and stored
records are produced by the real Tangible DDD runtime.

## What the scenario exercises

- `tangible_lms`: a certification journey long process and learning-journey
  Biography.
- `tangible_quiz`: a diagnostic/capstone assessment long process and assessment
  Biography.
- `tgbl_cred`: a credential-issuance long process, credential/portfolio
  Biographies, and a two-phase `WorkflowHandler` routine.
- `tangible_datastream`: an evidence-export long process plus stream/delivery
  Biographies.
- Cross-consumer integration listeners, process ignition via recorded
  `ignited_by_event_id`, Action Scheduler wake-ups, correlation restoration,
  and exact trace/Biography navigation in both directions.

Synthetic external boundaries start new correlations. A waiting long process
resumes its stored correlation as usual. This intentionally demonstrates that
one aggregate Biography can cross several honest stories; the fixture never
rewrites those correlations into one synthetic super-correlation.

## Timing

- Milestone facts schedule the next synthetic external boundary after 25
  seconds.
- The Cred routine handles one work item per pass and requests another pass
  after 18 seconds.
- Selected synthetic acts perform fixed measured work between 140ms and 1.15s;
  Cred routine items use a fixed 120-650ms profile. Only newly started runs
  contain these persisted command-audit durations.
- Automatic mode starts a new scenario every 10 minutes, with the first spawn
  scheduled five seconds after enabling it.

Action Scheduler and the site's cron cadence determine when due actions really
run. The verified DDEV site executes cron once per minute, so the dashboard
reveals new pieces on plausible minute-scale ticks rather than compressing the
whole story into one request.

## Requirements

- A winning Tangible DDD runtime with consumer-module support (`>=0.6.2`).
- Active LMS, Quiz, Cred, and Datastream plugins, each registered as a top-level
  DDD consumer.
- Action Scheduler and a functioning WordPress cron runner.
- The host service IDs in `ModuleManifest` must match the installed consumer
  versions. The sidecar deliberately fails readiness when a host is absent.

Never deploy this plugin to production. It creates synthetic operational and
domain records in all four host consumers.

## Local DDEV installation

Igor's LMS monorepo `.wp-env.json` mounts `./plugins/lms` and
`./plugins/quiz` as separate WordPress plugins. The equivalent setup in the
`anything` DDEV checkout is two top-level plugin symlinks:

```bash
cd /Users/titustc/tgbl/anything
ln -s lms-monorepo/plugins/lms wp-content/plugins/lms
ln -s lms-monorepo/plugins/quiz wp-content/plugins/quiz
ln -s ../../.worktrees/tangible-ddd-dddash-v2/tools/mega-trace \
  wp-content/plugins/tangible-ddd-mega-trace
```

Use the actual Tangible DDD checkout path in the final symlink when working
outside this feature worktree. Then activate the hosts and sidecar through
WordPress so their migrations and registration hooks run:

```bash
ddev wp plugin activate sfwd-lms tangible-cred tangible-datastream lms quiz \
  tangible-ddd-mega-trace
```

The 2026-07-22 verification uncovered two host-fleet issues: a cross-plugin
Doctrine manager reset during LMS/Quiz upgrades and incompatible broad
`Tangible\\` Composer loaders whose winner depends on plugin order. Plugin
reordering was used only to prove the latter collision; it is not a production
fix. Until that collision is fixed in the hosts, keep LMS and Quiz after
Datastream in the local active-plugin order as shown above.

## Running it

Open **Tools > DDD Mega Trace**. **Start scenario** creates one run immediately.
**Automatic runs** controls the recurring spawner. The page records the latest
scenario and main correlation and links directly to its unified trace.

For the verified local story, the trace completed with 32 commands, 30 facts,
four long processes, one Behaviour Workflow, and 13 cross-consumer handoffs.
The corresponding Biography view contained six aggregate identities across the
four hosts. Version gaps within the main trace were expected because later
external boundaries used new correlations.
