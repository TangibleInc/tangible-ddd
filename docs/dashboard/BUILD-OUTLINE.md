# TangibleDDD Dashboard — Build Outline

> **Status: HISTORICAL V1 BUILD PLAN.** The framework dashboard now lives in
> `ddd-wordpress/Admin/Dashboard`. This document preserves the plan that led to
> it; its old middleware and API names are not current integration guidance.
>
> **See also (older / partially superseded):** `docs/ddd-drill-inspector-dashboard-plan.md`,
> `docs/outbox-transport-payload-strategies.md`.

**Legend:** ✅ exists in tangible-ddd today · 🔨 build now · 🔮 proposed / needs new infra.

---

## 0. Purpose, scope, non-goals

**What:** a read-side **observability dashboard** for tangible-ddd, living in `wp-admin`
(extends the existing `tangible-dddash` mu-plugin page), themed **Warm Blueprint**, marked with
the **Hexagon Causation** logo. It reads the six DDD tables and renders them as cold record
surfaces, warm live/exploratory surfaces, and (later) write actions.

**Scope (this round):** shell + theme, a generic table reader, the **Trace Explorer**, the
**Live command log**, the two **process viewers**, a health/flow overview, and (phased) framework
**actions**. All consumer-scoped (cred / datastream / tangible-ddd).

**Non-goals (for now):** new domain logic in consumers; the **Entity Lifecycle Lens** (gated on
unbuilt infra — see §6 Phase 6); true streaming (PHP-land → Heartbeat polling, §4.4).

**The one hard fact that shapes everything:** tangible-ddd today is **write-only**. Every table is
populated by middleware/repositories; **no read/query class, REST route, or admin reader exists for
any of the six tables** (verified — `audit.php` has only `command_audit_preflight()` /
`command_audit_finalise()`, no reader). So the dashboard is **mostly net-new read code over an
existing write substrate**, not a skin over existing queries.

---

## 1. Ground truth (verified) — what tangible-ddd gives us

### 1.1 The six tables (per-consumer)
Installed in `ddd-wordpress/tables.php`. Name pattern: `{wp_prefix}{consumer_prefix}_<table>`
(e.g. `wp_tgbl_datastream_command_audit`), via `$config->table('<name>')`. Schema version constant
`DDD_SCHEMA_VERSION = 2` in `ddd-wordpress/migrations.php:35`, stored per-consumer as option
`{prefix}_ddd_schema_version`. (v2 added `causation_id`/`causation_type` to command_audit.)

| Table | Role | Importance | Temp |
|---|---|---|---|
| `command_audit` | command/trace spine | ★★★ | cold (but #1) |
| `integration_outbox` | event delivery queue | ★★ | cold + actions |
| `integration_dlq` | dead letters | ★★ | cold + actions |
| `long_processes` | saga / process-manager | ★★ | hybrid |
| `behaviour_workflows` | retry/notify behaviour chains | ★★ | hybrid |
| `behaviour_workflow_items` | per-step work-item ledger | ★ | cold |
| `entity_touches` | aggregate touch log | ★ | 🔮 **does not exist** (would be v3) |

### 1.2 `command_audit` columns — the spine (`tables.php:139-176`)
`id`, `command_id CHAR(32) UNIQUE`, `correlation_id CHAR(36) NULL`, `command_name VARCHAR(255)`,
`status VARCHAR(16)` (`in_progress`→`success`/`error`), `source VARCHAR(16)` (`cli`/`system`/`user`),
`source_id VARCHAR(64)`, `causation_id VARCHAR(64) NULL`, `causation_type VARCHAR(32) NULL`
(`integration_event`/`long_process`), `blog_id`, `duration_ms`, `peak_memory_bytes`, `started_at`,
`ended_at NULL`, `parameters JSON`, `events JSON` (`[{name}]`), `error JSON` (`{type,message,code}`),
`environment JSON` (`{php,wp,plugin}`). Indexes: `idx_correlation_id`, `idx_started_at`,
`idx_command_name`, `idx_status`, `idx_source`, `idx_causation`, `idx_blog_started`.
**There is no `aggregate_id` column** — entity identity is only (by convention) inside
`parameters`/`events` JSON.

Write path: `Application/Logging/CommandAuditMiddleware.php` → `ddd-wordpress/audit.php`
(`command_audit_preflight()` insert @ `audit.php:30`, `command_audit_finalise()` update @ `audit.php:55`).

### 1.3 Correlation / causation model (`Application/Correlation/CorrelationContext.php`)
Request-scoped static context. API: `init/get/peek/set` (correlation_id),
`set_command_id/command_id`, `set_causation(id,type)/causation_id/causation_type/clear_causation`,
`set_sequence/sequence/next_sequence`, `enter/leave/with/depth/reset` (scope stack).
Causation **set-points**:
- integration events: `CorrelationContext::set_causation($wrapped['__event_id'], 'integration_event')`
  (`ddd-wordpress/integration-events.php:73`).
- long processes: `set_causation($process->get_id(), 'long_process')` then `clear_causation()`
  after dispatch (`Application/Process/ProcessRunner.php:365,369`).

**A trace = derived, not stored.** To assemble one for a `correlation_id`:
```sql
SELECT * FROM {p}_command_audit     WHERE correlation_id = ? ORDER BY started_at;  -- spans
SELECT * FROM {p}_integration_outbox WHERE correlation_id = ? ORDER BY created_at;  -- event edges
```
then resolve causation: a command's `causation_id` (when `causation_type='integration_event'`)
points at an outbox `event_id`; (`long_process`) points at a `long_processes.id`. Reverse via
`idx_causation` to find children.

### 1.4 TWO process patterns — do not conflate
| | **LongProcess** | **BehaviourWorkflow** |
|---|---|---|
| table | `long_processes` | `behaviour_workflows` + `behaviour_workflow_items` |
| code | `Application/Process/LongProcess.php`, `ProcessRunner.php` | `Application/BehaviourWorkflows/WorkflowHandler.php`, `Domain/BehaviourWorkflow.php` |
| steps | reflection methods, declaration order | `behaviour_configs[]` array + per-item work ledger |
| compensation | ✅ full saga: undo methods reverse-order, `begin_undo/advance_undo/finish_undo`; checkpoints in `ProcessSteps` VO (`steps/compensations/checkpoints/step_index/undo_index/failure_msg`) | ❌ none — failure handled by **forking** a child workflow (`root_workflow_id=parent`) |
| await | ✅ native `AwaitEvent` → `waiting_for` + `match_criteria`, status `suspended` | custom logic; item status `waiting` |
| correlation | ✅ has `correlation_id` column | ❌ **no `correlation_id`** — keyed by `ref_type`+`ref_id` |
| reschedule | `ProcessRunner` via ActionScheduler | `reschedule_interval=5s`, `fork_delay=30s`, `max_retries` (WorkflowHandler) |
| status enum | `pending/running/scheduled/suspended/completed/failed` | items: `pending/waiting/failed/done/skipped`; wf: `is_complete`/`is_failed` |

**Consequence for the UI:** the saga viewer with forward + **compensation lane** + checkpoints maps to
**LongProcess only**. BehaviourWorkflow needs a *different* viz (phase/idx cursor + item ledger +
fork tree). And BehaviourWorkflow → trace linkage is **indirect** (no correlation_id; only via the
commands it dispatches).

### 1.5 Outbox / DLQ machinery
- `Infra/Persistence/OutboxRepository.php`: `write(event, correlation_id, command_id)`,
  `mark_failed()` (exponential backoff → `next_attempt_at`, append `error_history`),
  `move_to_dlq()` (insert `integration_dlq` with `final_error`, set outbox `status='dlq'`, fire
  `OutboxDeadLettered`).
- `Application/Outbox/OutboxProcessor.php::process_batch()`: release stale locks → fetch pending →
  pessimistic lock (`locked_until`/`locked_by`) → publish via ActionScheduler → complete / retry / dlq.
- `Application/Infrastructure/OutboxDeadLettered.php`: fires `{prefix}_outbox_dlq` + `tangible_ddd_outbox_dlq`.
- **`purge_completed(int $older_than_days=30)`** defined `IOutboxRepository.php:91` + impl
  `OutboxRepository.php:277` — **DEAD CODE, invoked nowhere.** (This is the home for `PurgeOutboxCommand`, §2.7.)

### 1.6 Persistence seam (`Infra/Persistence/Shared/PersistsAggregatesRepository.php`)
Abstract `get_aggregate_class():string`, `persist(Aggregate):void`. Concrete
`save(Aggregate):void` = type-check (`TypeMismatchException`) → `persist()` →
`$this->events->collect_from($aggregate)`. Ctor: `EventsUnitOfWork $events`.
`Entity` (`Domain/Shared/Entity.php`): `protected ?int $id`, `get_id/set_id`. `Aggregate`
adds `RecordsDomainEvents` (`event()`, `pull_events()`). All consumer repos extend the seam:
cred `LicenseRepository`/`EarningRepository`, datastream `CapturedEventRepository`/`DestinationRepository`.
**The single chokepoint** an `entity_touches` recorder would hook (§6 Phase 6).

### 1.7 What does NOT exist (so we don't pretend it does)
- ❌ Any **read/query** layer, REST route, or admin reader for the six tables.
- ❌ `entity_touches` table.
- ❌ Aggregate **versioning / optimistic-lock / CAS** in core. *Toehold:* cred `LicenseRepository`
  writes a `version` column (`get_version() ?: 1`) — **written but not enforced**, consumer-only.
- ❌ A `correlations` (trace registry) table — correlation is only a column on 4 tables.
- ❌ Framework operational commands (replay/purge/resume/…); §2.7 proposes them.

---

## 2. Surfaces to build (organized cold → warm)

For each: **data source** (exact table/query), what it shows, links out, status.

### 2.1 Shell ✅(page)/🔨(skin)
Existing `tangible-dddash` admin page. Build: Warm Blueprint theme, Hexagon mark, **consumer
selector** (sets the active `{prefix}` → scopes every query), top nav (Flow · Trace · Processes ·
Tables · Live), **auto-collapse admin menu** (`admin_body_class` += `folded`, §5). PHP-render +
one vanilla JS bundle. No React/build.

### 2.2 ❄ Tables (cold) 🔨
A generic, reusable table reader (sort / filter / paginate) bound to each table. Priority order:
`command_audit` ★★★ (full toolbar; row → command detail + trace), then `integration_outbox`,
`integration_dlq`, `long_processes`, `behaviour_workflows`, `behaviour_workflow_items`.
`entity_touches` 🔮 (rendered as "proposed/empty").

### 2.3 ♨ Trace Explorer 🔨 — the marquee
**Data:** §1.3 assembler — `command_audit` rows for a `correlation_id` (spans) + `integration_outbox`
rows (event edges) + causation resolution; pull related `long_processes` by `correlation_id`.
Render the **waterfall** (spans positioned by `started_at`+`duration_ms`, causation as indent tree,
wait/delay bars for reschedules, workflow as sub-assembly, span-detail footer from `parameters`/
`events`/`error`). Entry: a `correlation_id` from anywhere → here.

### 2.4 ♨ Live command log 🔨
**Data:** `command_audit` delta since last seen `id`/`started_at`, via **Heartbeat tick** (§4.4).
Newest-first tail, type/status colored. Consumer-scoped; "all" = union across prefixes (§7.5).

### 2.5 ◐ Process viewers 🔨 — TWO of them, named **Sagas** vs **Workflows** (DECIDED §7.4)
- **Sagas** (= `LongProcess`, table `long_processes`): forward steps + **compensation lane** +
  checkpoints + `waiting_for`/`match_criteria` (await) + status. Expand-in-row. Has `correlation_id`.
- **Workflows** (= `BehaviourWorkflow`, tables `behaviour_workflows` + `behaviour_workflow_items`):
  `current_idx`/`current_phase` cursor, the **item ledger** (status per item), **fork tree**
  (`root_workflow_id`), retries. Links to entity via `ref_type`+`ref_id`; to trace only indirectly.
- Both live under one **"Processes"** nav, two surfaces. UX labels: Sagas / Workflows.
- **OPEN (my work):** how the **Trace Explorer** renders both inline. Sagas join by `correlation_id`
  (direct). Workflows have none → shown via the commands they dispatch (a workflow "lane" grouping
  those spans). Design TBD; flagged in §7.4.

### 2.6 ◐/♨ Entity Lifecycle Lens 🔨 — IN SCOPE (DECIDED §7.3, builds in Phase 6)
Build with full infra: `entity_touches` (schema v3) + versioning seam. Finder (consumer+type+id) +
touch timeline + version progression + CAS-conflict marker. Sequenced last (touches core write-path).

### 2.7 ⚡ Actions — framework commands 🔮 (net-new in tangible-ddd proper)
Operational commands the dashboard issues; each **self-audits** (writes to `tangible_ddd.command_audit`,
source=dashboard). Proposed set:

| Command | Effect | Target | Destructive | Notes |
|---|---|---|---|---|
| `ReplayDeadLetterCommand` | re-enqueue DLQ entry → outbox | `integration_dlq` | no | **build first** (read-safe trio) |
| `ResumeProcessCommand` | re-dispatch stuck saga/process | `long_processes` | no | **build first** |
| `RetryCommand` | re-dispatch failed cmd from stored `parameters` JSON | `command_audit` | no | **build first** — audit-native replay |
| `DrainOutboxCommand` | force `process_batch()` now | `integration_outbox` | no | read-safe, low blast radius |
| `PurgeOutboxCommand` | delete **completed + aged** rows only (**wire dead `purge_completed()`**) | `integration_outbox` | **yes (safe)** | ✅ chosen safe-destructive #1 — bounded: completed-only, age-gated, confirm |
| `DiscardDeadLetterCommand` | drop a single abandoned DLQ entry | `integration_dlq` | **yes (safe)** | ✅ chosen safe-destructive #2 — single row, explicit, confirm |
| `CancelProcessCommand` | abort saga → run compensation lane | `long_processes` | **yes** | DEFER — operational, heavy; later phase |
| `PruneAuditCommand` | retention GC on audit | `command_audit` | **yes (dangerous)** | **DEFER / never auto** — destroys the audit trail = the whole point |

**DECIDED (§7.2):** ship read-safe trio (Replay / Resume / Retry) + Drain, plus **two safe-destructive**
commands: `PurgeOutboxCommand` (completed+aged only) and `DiscardDeadLetterCommand` (single entry).
`PruneAudit` and bulk `CancelProcess` deferred — pruning the audit log is dangerous (it's the
observability spine itself).

### 2.8 Flow / health overview 🔨
KPI tiles + counts (DLQ depth, outbox pending, error rate, p95) via Heartbeat + count queries;
attention bands (failed commands, dead letters, stuck/suspended processes). Optional: a WP **Site
Health** status test mirroring DDD health.

### 2.9 Storage / table sizes 🔨 (DECIDED §7.2)
A read-only **storage panel**: per-consumer, the on-disk size of each of the six operational tables
(+ row counts, growth). Source:
```sql
SELECT table_name, table_rows, data_length, index_length
FROM information_schema.TABLES
WHERE table_schema = DATABASE() AND table_name LIKE '{wp_prefix}{consumer_prefix}_%';
```
Why: operational tables grow unbounded (outbox/audit). This surface makes growth visible and
**motivates** the safe-destructive `PurgeOutboxCommand` (§2.7) — see the size, then purge.

---

## 3. Relationship / navigation graph (the "correlation ID → trace" web)

### 3.1 Data edges
Every edge is a click-through. `via` = the key that carries you.

| From | via | To |
|---|---|---|
| command row (any table) | `correlation_id` | **Trace Explorer** |
| command row | `command_id` | command detail (parameters/events/error JSON) |
| trace span | `causation_id`+`causation_type` | parent (outbox event \| long_process); reverse → children |
| command / process | `correlation_id` | related outbox + dlq + process rows |
| `long_processes` row | `correlation_id` | Trace Explorer (direct) |
| `behaviour_workflows` row | `ref_type`+`ref_id` | the referenced entity |
| `behaviour_workflows` row | *(its dispatched commands' correlation)* | Trace Explorer **(INDIRECT — no correlation_id on workflow)** |
| `behaviour_workflow_items` row | `workflow_id` | parent workflow |
| `integration_outbox` row | `command_id` | the command that published it |
| `integration_dlq` row | `outbox_id` / `event_id` | replay action; `correlation_id` → trace |
| aggregate (type+id) | `entity_touches` | 🔮 Lifecycle Lens |
| dashboard action | `command_id` | its own audit row (self-observability) |

### 3.2 Screen navigation map (UX) — "click this → that screen"

**Screen inventory (destinations):** Flow (default landing) · Tables (one per DDD table;
`command_audit` is the hub) · Trace Explorer (one correlation) · Processes → LongProcess viewer /
BehaviourWorkflow viewer · Live log · 🔮 Entity Lifecycle. **Not screens:** command/span **detail
(drawer)**, confirm dialogs (modal), DLQ (it's a Table; replay is an action).

**Entry points (how you arrive at a screen):**
- Top nav tabs: Flow · Trace · Processes · Tables · Live.
- **⌘K command palette** → search by `command_id` / `correlation_id` / entity `type+id` → jumps to the matching screen.
- **Deep links** — every screen is URL-addressable & shareable: `?screen=trace&corr=7f3a…c20a`,
  `?screen=table&t=command_audit&status=failed`, `?screen=process&kind=long&id=418`. (So "send a
  teammate this trace" just works.)
- Heartbeat badge "3 IN DLQ" → Tables/`integration_dlq`.
- Flow attention card → the specific Trace, or a pre-filtered Table.

**Click-through flows (the journeys):**
1. command row (any table) → click **`correlation_id` chip** → **Trace Explorer** (`?corr=`).
2. command row → click row → **command detail drawer** (parameters/events/error JSON); close → back to the table, scroll position kept.
3. Trace **span** → click → command detail **drawer** (same component).
4. Trace span → click **`↳ from <parent>`** → **re-center trace** on the causation parent (event/process); breadcrumb keeps the path back.
5. command/process detail → **`→ entity` link** → 🔮 Entity Lifecycle. *Until built:* disabled with tooltip "needs entity_touches".
6. Process viewer → **"Inspect trace"** → Trace Explorer. LongProcess = direct (`correlation_id`).
   **BehaviourWorkflow = indirect** (no `correlation_id`): resolve via one of its dispatched commands;
   if several correlations, show a small chooser. (UX caveat from §1.4.)
7. DLQ table row → **"Replay"** → **confirm modal** → action POST → toast "issued
   `ReplayDeadLetterCommand`" → the new command surfaces in **Live log** + its own audit row
   (self-observable). **No navigation** — you stay on the DLQ table; the row updates.
8. `behaviour_workflow_items` row → click → parent **BehaviourWorkflow viewer** (`workflow_id`).
9. Flow "stuck process" card → the **Process viewer** for that id.

**Drawer vs nav vs in-place (the rule):**
- **Drawer** (overlays, preserves the list behind): command detail, span detail. Reversible, keeps context.
- **Full-screen nav** (swaps the main view, updates the URL → deep-linkable): Trace Explorer,
  Process viewer, a Table, Lifecycle. Push to history; browser-back + in-app breadcrumb both work.
- **In-place mutate** (no nav, no URL change): filter / sort / paginate; replay / resume / retry
  actions (result reflected via the Heartbeat tick, not a page change).

---

## 4. Data access plan (read layer — all net-new)

### 4.1 Query classes (read repositories), per table
`AuditQuery`, `OutboxQuery`, `DlqQuery`, `LongProcessQuery`, `WorkflowQuery`, `WorkItemQuery`.
Each: filter (status/source/time/consumer), sort, paginate. Prefix-aware (consumer scope).

### 4.2 Trace assembler
`TraceQuery::assemble(correlation_id) → TraceDTO` per §1.3 (spans + outbox edges + causation
resolution + related processes). Pure read; no schema change.

### 4.3 REST routes — `tangible-ddd/v1/*` (`permission_callback = manage_options`, `X-WP-Nonce`)
`GET /audit`, `GET /audit/{command_id}`, `GET /trace/{correlation_id}`, `GET /outbox`, `GET /dlq`,
`GET /processes`, `GET /processes/{id}`, `GET /workflows`, `GET /workflows/{id}`, `GET /health`,
`GET /tables/{name}` (generic reader), `GET /consumers` (selector data).

### 4.4 Heartbeat tick (the "live" transport — PHP-land, no streaming)
Hook `heartbeat_received`; client sends `{cursor}`, server returns
`{rows, counts:{dlq,outbox,...}, health, cursor}`. `wp.heartbeat.interval('fast')` (5s) **only on
the dash screen**. Drives live log + badges + health dot. Honest: this is polling, not push.

### 4.5 Action POSTs 🔮 — `POST tangible-ddd/v1/actions/{replay|resume|retry|drain|purge|cancel|prune}`
Nonce + capability + confirm for destructive (§2.7). Dispatch the framework command via the bus.

---

## 5. Shell & theme (decided)
**Warm Blueprint** (warm paper `#FDFAF8`, indigo `#6359D6`, coral `#FD9597` = event, olive/tobacco/
crit semantics; League Spartan headings + system body + monospace data; faint indigo grid, squared
4px, flat, title-block header, instrument readouts, causation-as-wiring). **Hexagon Causation** mark
(static + a→f loader). **Consumer selector** scopes `{prefix}`. **Menu auto-collapse:**
```php
add_filter('admin_body_class', function ($c) {
    $s = get_current_screen();
    if ($s && $s->id === 'tools_page_tangible-dddash') $c .= ' folded'; // verify hook id
    return $c;
});
```

---

## 6. Build phases (dependency-ordered)

- **Phase 0 — Shell.** Skin the existing dddash page: Warm Blueprint, hex mark, consumer selector,
  menu-collapse, nav scaffold. No data yet.
- **Phase 1 — Read layer + Tables.** `AuditQuery` + `GET /audit` + generic table reader; ship
  `command_audit` table first, then the rest. (Unblocks everything.)
- **Phase 2 — Trace Explorer.** `TraceQuery` assembler + `GET /trace/{corr}` + the waterfall UI.
  The marquee surface.
- **Phase 3 — Live + Flow.** Heartbeat tick (live log delta + counts + health) + Flow overview tiles.
- **Phase 4 — Process viewers.** LongProcess saga viewer + BehaviourWorkflow viewer (two distinct UIs).
- **Phase 5 — Actions.** Read-safe first (Replay / Resume / Retry), then destructive behind confirm
  (Drain → Purge[wire dead code] / Cancel / Prune). Each self-audits.
- **Phase 6 🔨 — Lifecycle infra + lens (IN SCOPE, DECIDED §7.3).** Build the full infra:
  `entity_touches` table (**schema bump to v3**, `migrations.php`) + versioning seam
  (`IVersionedEntity` / `HasVersion` / CAS / `ConcurrencyException`) hooked at
  `PersistsAggregatesRepository::save()` (the one chokepoint, §1.6) → entity-touch recorder →
  Entity Lifecycle Lens UI. **Highest-risk phase** — it modifies core framework write-path + adds a
  migration + needs per-consumer opt-in (cred `License` already has an unenforced `version` toehold,
  §1.7). Sequence LAST among build work, or on a parallel branch, because a bug here can abort real
  writes — the recorder must be best-effort (try/catch, never block a real save).

---

## 7. Open questions / decisions pending

1. **`correlations` table?** ✅ **DECIDED: start with `GROUP BY correlation_id` scans** over
   `command_audit` (zero new schema). STILL OPEN: whether the eventual optimization is a
   **materialized read-model** (`correlations`: root entity, started/ended, span_count, status
   rollup, consumer; updated on `command_audit_finalise`) or a **fully-assed table** — defer that
   pick until the recent-traces rail / Flow rollups actually get slow. Surfaces that would benefit:
   Trace recent-list rail, Flow overview, "all consumers".
2. **Which framework commands?** ✅ **DECIDED (§2.7):** read-safe trio (Replay / Resume / Retry) +
   Drain, plus **two safe-destructive** (`PurgeOutboxCommand` completed+aged, `DiscardDeadLetterCommand`
   single entry). `PruneAudit` + bulk `CancelProcess` DEFERRED. Plus new **§2.9 Storage surface**
   (table disk sizes) to make growth visible and justify purges.
3. **`entity_touches` + versioning?** ✅ **DECIDED: build it, with all the infra** (§2.6, §6 Phase 6).
   entity_touches (schema v3) + the versioning/CAS seam at `PersistsAggregatesRepository::save()`.
   Sequenced last (modifies core write-path; recorder must be best-effort).
4. **Process viewers?** ✅ **DECIDED: two surfaces** under one "Processes" nav, labeled **Sagas**
   (`LongProcess`) and **Workflows** (`BehaviourWorkflow`). STILL OPEN (my design work): how the
   Trace Explorer renders both inline — Sagas join by `correlation_id`; Workflows have none, so
   they appear as a lane grouping the spans of the commands they dispatch.
5. **Cross-consumer "all" view:** union across prefixes for the global live tail / health — build
   when needed, not Phase 1.
6. **Streaming:** accept Heartbeat 5s poll as "live"; no SSE/WebSocket in PHP-FPM.

---

## 8. PROPOSED inventory (everything not-yet-real, one place)
- The entire **read/query layer** (§4.1–4.3) + **REST** + **Heartbeat** wiring.
- All **dashboard UI surfaces** (§2).
- `entity_touches` table (schema v3) + **versioning seam** (CAS).
- Maybe a `correlations` read-model (§7.1).
- The **7 framework commands** (§2.7) + action REST (§4.5).
- Wiring the dead `purge_completed()` (§1.5) behind `PurgeOutboxCommand`.

---

## 9. Dev tooling — the firehose seeder ✅ BUILT (2026-06-29)

**File:** `wp-content/mu-plugins/tangible-ddd-seeder.php` (dev-only mu-plugin, WP-CLI).
**Guard:** refuses unless `IS_DDEV_PROJECT` or `wp_get_environment_type() ∈ {local, development}` —
it writes/truncates real tables.

Generates **coherent, ID-consistent** DDD data into the real six tables so the dashboard is built
against real queries, not mocks. Resolves table names via the consumers' real `IDDDConfig`
(`new DatastreamConfig($wpdb->prefix)` → `wp_tangible_datastream_*`; `new Tangible\Cred\Infra\Config`
→ `wp_tgbl_cred_*`).

```bash
ddev wp tangible-ddd seed --consumer=datastream --count=20 --reset
ddev wp tangible-ddd seed --consumer=cred --scenario=failed --count=10
ddev wp tangible-ddd seed --consumer=datastream --firehose   # continuous trickle → Live view
```
Options: `--consumer=datastream|cred` · `--scenario=mixed|clean|failed|saga|workflow|slow` ·
`--count=N` · `--firehose` · `--reset` · `--blog=N`.

**Scenarios:** `clean` (3-cmd linked trace + 2 outbox events), `failed` (capture→match→delivery→DLQ
+ failed escalation), `saga` (`long_processes` row, suspended, with `steps` JSON incl.
compensations+checkpoints), `workflow` (`behaviour_workflows` parent + forked child via
`root_workflow_id` + items), `slow` (p95-buster).

**The "smart" guarantee (verified):** every `causation_type='integration_event'` command's
`causation_id` resolves to a real outbox `event_id` in the same correlation — checked 14/14 on first
run. So `TraceQuery::assemble()` (scan by `correlation_id` + join outbox + reverse causation) yields
real connected waterfalls. DLQ rows link to dead-lettered outbox rows; workflow forks link to parents.
