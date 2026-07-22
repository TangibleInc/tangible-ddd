# Trace Temporal Fidelity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give new Mega Trace runs truthful, deterministic execution-time variation and make DDDash show sparse cumulative elapsed time across short waits and multi-day hiatuses without leaking timeline marks through the sticky label column.

**Architecture:** The development sidecar declares fixed workloads on selected commands and sleeps inside their real command invocation, so existing correlation audit measures the delay. The read-side presenter adds numeric elapsed seconds while retaining local edge gaps; dashboard JavaScript formats cumulative labels and optional hiatus labels without generating empty ticks. CSS keeps the sticky label column above all timeline marks at both existing responsive widths.

**Tech Stack:** PHP 8.1+, PHPUnit 10/11, WordPress Heartbeat/REST dashboard, vanilla JavaScript, CSS, DDEV, Playwright CLI.

## Global Constraints

- Workloads are fixed: personalization 180ms, signal analysis 460ms, evidence verification 1,150ms, packaging 820ms, notification 140ms, and registry commit 360ms.
- Routine items are fixed: identity 120ms, assessment 260ms, completion 390ms, certificate 520ms, transcript 650ms, and badge 180ms.
- No production consumer, audit row, query result, or rendered duration may be fabricated.
- No gap expands in proportion to wall-clock duration and no empty periodic tick is generated.
- Gaps of at least 300 seconds receive a secondary local-gap label.
- Prime Radiant branding must not enter tracked product files.
- Use test-first red-green cycles and keep the implementation on `feature/dddash-v2-unified-trace`.

---

### Task 1: Measured Mega Trace Workloads

**Files:**
- Create: `tools/mega-trace/src/Command/SyntheticWorkload.php`
- Create: `tests/Unit/MegaTrace/SyntheticWorkloadTest.php`
- Modify: `tools/mega-trace/src/Command/PublishFactCommand.php`
- Modify: `tools/mega-trace/src/Lms/Application/Commands.php`
- Modify: `tools/mega-trace/src/Quiz/Application/Commands.php`
- Modify: `tools/mega-trace/src/Cred/Application/Commands.php`
- Modify: `tools/mega-trace/src/Datastream/Application/Commands.php`
- Modify: `tools/mega-trace/src/Cred/Application/BehaviourWorkflows/IssuanceRoutine.php`
- Modify: `tools/mega-trace/README.md`

**Interfaces:**
- Produces: `SyntheticWorkload::spend(int $milliseconds): void`, `SyntheticWorkload::microseconds(int $milliseconds): int`, and `SyntheticWorkload::routine_item_ms(string $item): int`.
- Produces: `PublishFactCommand::synthetic_workload_ms(): int`, backed by overridable `protected const SYNTHETIC_WORK_MS = 0`.

- [x] **Step 1: Write the failing workload profile test**

Create command instances and assert the six selected values, an unselected command value of zero, the six routine-item values, and `SyntheticWorkload::microseconds(1_150) === 1_150_000`. Assert values above 1,200 throw `InvalidArgumentException`.

- [x] **Step 2: Run the focused test and verify red**

Run: `vendor/bin/phpunit tests/Unit/MegaTrace/SyntheticWorkloadTest.php`

Expected: FAIL because `SyntheticWorkload` and `synthetic_workload_ms()` do not exist.

- [x] **Step 3: Implement the bounded workload mechanism**

Use this sidecar-only utility:

```php
final class SyntheticWorkload
{
    public const MAX_MILLISECONDS = 1_200;

    public static function microseconds(int $milliseconds): int
    {
        if ($milliseconds < 0 || $milliseconds > self::MAX_MILLISECONDS) {
            throw new InvalidArgumentException('Synthetic work must be between 0ms and 1200ms.');
        }
        return $milliseconds * 1_000;
    }

    public static function spend(int $milliseconds): void
    {
        $microseconds = self::microseconds($milliseconds);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    public static function routine_item_ms(string $item): int
    {
        return match ($item) {
            'identity' => 120,
            'assessment' => 260,
            'completion' => 390,
            'certificate' => 520,
            'transcript' => 650,
            'badge' => 180,
            default => 0,
        };
    }
}
```

Have `PublishFactCommand::handle()` call `SyntheticWorkload::spend($this->synthetic_workload_ms())` before publishing. Concrete selected command classes override only `SYNTHETIC_WORK_MS`. Have `IssuanceRoutine::execute_one()` spend the item-specific workload before publishing its completion fact.

- [x] **Step 4: Run focused Mega Trace tests and verify green**

Run: `vendor/bin/phpunit tests/Unit/MegaTrace/SyntheticWorkloadTest.php tests/Unit/MegaTrace/IssuanceRoutineTest.php`

Expected: PASS with the existing routine test taking roughly 120ms longer because it executes the identity item honestly.

- [x] **Step 5: Document and commit the measured profile**

Update the README Timing section with the selected workload range and the rule that only new runs contain the durations. Commit:

```bash
git add tools/mega-trace tests/Unit/MegaTrace/SyntheticWorkloadTest.php
git commit -m "feat(devtools): add measured mega trace workloads"
```

### Task 2: Sparse Cumulative Elapsed Time

**Files:**
- Modify: `tests/Unit/Dashboard/TraceTimelinePresenterTest.php`
- Modify: `ddd-wordpress/Admin/Dashboard/Query/TraceTimelinePresenter.php`
- Modify: `tests/Unit/Dashboard/DashboardArtifactsTest.php`
- Modify: `ddd-wordpress/Admin/Dashboard/assets/dashboard.js`
- Modify: `ddd-wordpress/Admin/Dashboard/assets/dashboard.css`

**Interfaces:**
- Consumes: existing numeric `gap_before` local-edge duration and compressed `start_pct`.
- Produces: numeric `elapsed_s` on every presented node, measured from the earliest resolved node.
- Produces: `fmtTraceTime(seconds)` returning cumulative labels such as `+1m` and `+2d 3m`.

- [x] **Step 1: Write failing presenter and artifact tests**

Build one causal chain at `+1m`, `+2m`, `+3m`, and `+2d 3m`. Assert command-node `elapsed_s` values `[60, 120, 180, 172980]`, the final local `gap_before` is `172800`, and only that waking node represents the two-day gap. Add artifact assertions for `fmtTraceTime`, `elapsed_s`, the 300-second hiatus condition, and separate `tl-gap-label`/`tl-hiatus` classes.

- [x] **Step 2: Run focused dashboard tests and verify red**

Run: `vendor/bin/phpunit tests/Unit/Dashboard/TraceTimelinePresenterTest.php tests/Unit/Dashboard/DashboardArtifactsTest.php`

Expected: FAIL because the presenter omits `elapsed_s` and the asset lacks cumulative formatting.

- [x] **Step 3: Add numeric elapsed time in the presenter**

Calculate the existing earliest resolved timestamp before mapping output nodes. Add:

```php
$node['elapsed_s'] = $minTimestamp > 0
    ? max(0, (int) $node['ts'] - $minTimestamp)
    : 0;
```

Keep `gap_before`, `start_pct`, `width_pct`, and fixed compressed gap units unchanged.

- [x] **Step 4: Render cumulative and exceptional-gap labels**

Add a compact two-unit JavaScript formatter that skips zero-value units, so `172980` becomes `+2d 3m`. For each existing gap marker, render the node's cumulative `elapsed_s`; when `gap_before >= 300`, append a secondary local label such as `2d gap`. Do not create an interval loop or synthesized tick collection.

- [x] **Step 5: Run focused tests and JavaScript syntax verification**

Run:

```bash
vendor/bin/phpunit tests/Unit/Dashboard/TraceTimelinePresenterTest.php tests/Unit/Dashboard/DashboardArtifactsTest.php
node --check ddd-wordpress/Admin/Dashboard/assets/dashboard.js
```

Expected: focused tests PASS and Node exits 0.

### Task 3: Sticky Masking, Runtime Proof, and Delivery

**Files:**
- Modify: `tests/Unit/Dashboard/DashboardArtifactsTest.php`
- Modify: `ddd-wordpress/Admin/Dashboard/assets/dashboard.css`
- Modify: `docs/superpowers/plans/2026-07-22-trace-temporal-fidelity.md`

**Interfaces:**
- Consumes: `.tl-gaps` at `z-index: 4` and the existing 320px/250px label-column geometry.
- Produces: sticky `.ruler .rl` and `.slabel` layers above timeline marks; responsive `.tl-gaps` geometry aligned at 250px below 800px.

- [x] **Step 1: Extend the artifact test and verify red**

Assert sticky labels use `z-index: 5`, the gap overlay remains below them, and the existing `@media(max-width:800px)` block sets `.tl-gaps{grid-template-columns:250px 1fr}`.

- [x] **Step 2: Implement the minimal stacking and geometry fix**

Raise the sticky label selector from 3 to 5, retain opaque label backgrounds, and add the responsive overlay grid track. Do not add clipping or hide the timeline lane itself.

- [x] **Step 3: Run all automated verification**

Run:

```bash
vendor/bin/phpunit
node --check ddd-wordpress/Admin/Dashboard/assets/dashboard.js
git diff --check
```

Expected: all 568 tests PASS, JavaScript parses, and no whitespace errors are reported.

- [x] **Step 4: Prove the runtime in DDEV**

Start a new scenario from `https://anything.ddev.site/wp-admin/tools.php?page=tddd-mega-trace`. Verify its newly persisted audit rows include the six selected workload bands. Open its trace, confirm cumulative `+1m`, `+2m`, and later labels update through Heartbeat, then horizontally scroll at desktop and narrow widths to confirm bars and gap marks disappear beneath the sticky label column.

- [ ] **Step 5: Commit, push, and verify CI**

```bash
git add ddd-wordpress tests/Unit/Dashboard docs/superpowers/plans/2026-07-22-trace-temporal-fidelity.md
git commit -m "feat(dashboard): show sparse cumulative trace time"
git push origin feature/dddash-v2-unified-trace
gh pr checks 34 --watch --interval 5
```

Expected: PR #34 points at the new head and PHPUnit CI passes.
