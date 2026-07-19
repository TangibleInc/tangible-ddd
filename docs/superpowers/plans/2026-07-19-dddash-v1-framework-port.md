# Tangible DDDash v1 Framework Port Plan

**Goal:** Replace the procedural DDDash runtime with a namespaced implementation
inside `tangible-ddd`, preserving current behavior and keeping the mu-plugin as
the reference.

**Architecture:** Consumer-scoped query services sit behind a minimal database
port. WordPress-specific controllers expose those services through the existing
REST, heartbeat, and Tools-page contracts. The winner loader registers one
dashboard composition root.

**Stack:** PHP 8.1, WordPress REST/admin/Heartbeat APIs, PHPUnit, vanilla CSS/JS.

---

## 1. Characterize the contracts

- Add a scripted database fake and consumer/catalog fakes.
- Add failing tests for catalog discovery, query filtering/normalization,
  trace assembly, action mapping, and route registration.
- Run each focused test and observe the expected pre-implementation failure.

## 2. Build the read-side foundation

- Add the database port and `$wpdb` adapter.
- Add consumer descriptor, catalog, and prefix-only config.
- Port the nine v1 query classes without changing response keys.
- Make focused query tests pass.

## 3. Build the WordPress boundary

- Add action, REST, heartbeat, and admin-page controllers.
- Add the dashboard composition root and procedural registration file.
- Register the dashboard from the newest-wins loader.
- Verify legacy route and page takeover in tests.

## 4. Extract the interface

- Move the reference page markup into a PHP template.
- Move the reference styles and script into separately enqueued assets.
- Preserve boot data, selectors, element IDs, and behavior.

## 5. Verify parity

- Run the full unit suite and PHPStan.
- Run WordPress integration tests and compare representative REST results.
- Exercise every view and action with browser checks at desktop and mobile
  widths; inspect console errors and layout overlap.
- Record only genuine v2 opportunities in documentation; do not add v2
  behavior during parity work.
