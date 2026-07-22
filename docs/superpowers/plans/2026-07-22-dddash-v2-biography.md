# DDDash V2 Aggregate Biography Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a consumer-scoped Aggregate Biography that reads retained `touches` rows, links each entry to its recorded command, fact, and correlation, and lets an operator move between Biography and unified Trace without inventing missing evidence.

**Architecture:** `BiographyQuery` is the read-side boundary over one consumer's tables. It exposes a paginated aggregate finder and one canonical aggregate timeline; optional command/fact data is left-joined by recorded IDs. `RestController` adapts requests, while the existing dashboard renders the finder and timeline. Unified trace fragments also read touch rows so a recorded trace fact can link back to the owning consumer's Biography.

**Tech Stack:** PHP 8.1, WordPress REST API, wpdb via the dashboard `Database` port, PHPUnit, vanilla JavaScript/CSS, DDEV, Playwright.

## Global Constraints

- Biography remains consumer-scoped; unified trace remains cross-consumer.
- Aggregate identity is exactly `(canonical aggregate, aggregate_id)` from `touches`.
- Treat `touches` as a retention-bounded read model, not an event store or write authority.
- Only show command, fact, trace, and reverse-Biography links backed by recorded IDs.
- Do not expose Act / Fact / Trajectory as the primary public UI vocabulary.
- Preserve all V1 views, operations, filters, heartbeat behavior, and the time scrubber.

---

### Task 1: Biography Read Model

**Files:**
- Create: `ddd-wordpress/Admin/Dashboard/Query/BiographyQuery.php`
- Create: `tests/Unit/Dashboard/BiographyQueryTest.php`

**Interfaces:**
- Produces: `BiographyQuery::recent(array $filters): array`
- Produces: `BiographyQuery::read(string $aggregate, string $aggregateId): array`

- [ ] Write a failing test proving finder pagination/search and detail joins use the configured consumer tables.
- [ ] Run `vendor/bin/phpunit tests/Unit/Dashboard/BiographyQueryTest.php` and verify the class-missing failure.
- [ ] Implement grouped recent aggregates ordered by last touch and a version-ordered detail query with left joins to `command_audit` and `integration_outbox`.
- [ ] Normalize numeric fields and return stable empty results when no rows exist.
- [ ] Re-run the focused test and verify it passes.

### Task 2: REST Boundary

**Files:**
- Modify: `ddd-wordpress/Admin/Dashboard/RestController.php`
- Modify: `tests/Unit/Dashboard/WordPressBoundaryTest.php`

**Interfaces:**
- Produces: `GET /tangible-ddd/v1/biographies`
- Produces: `GET /tangible-ddd/v1/biography?aggregate=...&aggregate_id=...`

- [ ] Write failing boundary tests for consumer validation, finder filters, and required aggregate identity.
- [ ] Run the focused boundary tests and verify the routes are absent.
- [ ] Add thin route callbacks that delegate to `BiographyQuery`.
- [ ] Re-run the boundary tests and verify they pass.

### Task 3: Biography Dashboard Lens

**Files:**
- Modify: `ddd-wordpress/Admin/Dashboard/template.php`
- Modify: `ddd-wordpress/Admin/Dashboard/assets/dashboard.js`
- Modify: `ddd-wordpress/Admin/Dashboard/assets/dashboard.css`
- Modify: `tests/Unit/Dashboard/DashboardArtifactsTest.php`

**Interfaces:**
- Consumes: the two Biography REST endpoints.
- Produces: hash routes `#biography` and `#biography/<aggregate>/<aggregate_id>`.

- [ ] Add failing artifact assertions for the Biography nav, finder, timeline, and deep-link route.
- [ ] Run the artifact test and verify the missing-markup failure.
- [ ] Add a compact finder with search, pagination, canonical name, ID, touch count, latest version, last operation, and last-touch time.
- [ ] Add a timeline detail with version, operation, fact, command, and trace controls; keep the aggregate name and ID visible while scrolling.
- [ ] Make consumer changes refresh the current Biography view without leaking another consumer's identity.
- [ ] Re-run artifact tests and `node --check`.

### Task 4: Trace-to-Biography Joint

**Files:**
- Modify: `ddd-wordpress/Admin/Dashboard/Query/TraceFragmentReader.php`
- Modify: `ddd-src/Application/Tracing/TraceStitcher.php`
- Modify: `ddd-wordpress/Admin/Dashboard/assets/dashboard.js`
- Modify: `tests/Unit/Dashboard/TraceFragmentReaderTest.php`
- Modify: `tests/Unit/Tracing/TraceStitcherTest.php`

**Interfaces:**
- Extends each consumer fragment with `touches` rows for the correlation.
- Adds recorded touch links to matching event-node detail without changing graph topology.

- [ ] Write failing tests proving touches attach by recorded `event_id` and retain consumer provenance.
- [ ] Run focused tests and verify the missing-fragment behavior.
- [ ] Read the consumer's `touches` table as a fifth optional fragment surface.
- [ ] Attach touch metadata to matching recorded fact nodes; do not derive it from event payloads.
- [ ] Render aggregate links in trace node detail and route them to the owning consumer before opening Biography.
- [ ] Re-run focused PHP and JS checks.

### Task 5: Distinct Stable Consumer Accents

**Files:**
- Modify: `ddd-wordpress/Admin/Dashboard/ConsumerDefinition.php`
- Modify: `tests/Unit/Dashboard/ConsumerCatalogTest.php`

**Interfaces:**
- Preserves explicit `#RRGGBB` overrides.
- Produces a stable curated fallback from the consumer key.

- [ ] Write a failing test proving the four live Tangible consumer keys receive distinct fallback accents.
- [ ] Run the test and verify the current LMS/Datastream collision.
- [ ] Replace the low-bit CRC palette selection with a stable SHA-256-derived selection over an expanded curated palette.
- [ ] Re-run the test and verify explicit overrides remain unchanged.

### Task 6: End-to-End Verification

**Files:**
- Modify: `docs/dashboard/V2-OUTLINE.md`
- Modify: `tools/mega-trace/README.md`

- [ ] Run all dashboard, tracing, and mega-trace unit tests.
- [ ] Run the complete PHPUnit suite and PHP/JS syntax checks.
- [ ] Query the live Biography endpoints against the completed mega-trace correlation and verify every scenario aggregate/version.
- [ ] Open DDDash in Playwright at desktop and mobile widths; verify Biography finder/detail, trace-to-Biography navigation, Biography-to-trace navigation, accents, overflow, and non-overlap.
- [ ] Update docs with implemented behavior and the reproducible local LMS/Quiz installation path.
- [ ] Review the diff, commit coherent checkpoints, push the branch, and open the V2 PR.
