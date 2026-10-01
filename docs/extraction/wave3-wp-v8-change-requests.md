# Wave 3: wp-v8 change requests

Author: wp-v8 (branch `wave3/wp-v8`, based on `8c74686`). Owned paths: `packages/ddd-wp/{src,wordpress,tests}/**` (except `wordpress/self/index.php`), `tests/Unit/**` except `tests/Unit/{Loader,Process}/**`, `tests/Integration/**` except `tests/Integration/Conformance/**`, `tests/Fakes/**`, `tests/wp-stubs.php`, and this file.

Every request below is additive. No ratified interface, frozen FQCN, persisted name or hook signature was changed.

## WP8-1 (schema, additive): `long_processes.start_path`

- **What.** A fourth nullable column on `{prefix}_long_processes` in schema v8, `start_path VARCHAR(16) NULL`. The v8 `WpdbProcessStore` writes `ignition` from `insertIgnited()` and `manual` from `insert()`. Rows written by a 0.6 copy keep it NULL.
- **Why.** Two binding requirements conflict on wp without it:
  - the ruling on #76 (register 3.8, 5.3 step 6, 7.3 sequence 2): inside the ignition lock, `insertIgnited` also checks `long_processes.ignited_by_event_id` for the class, so a saga ignited by a 0.6 winner between a rollback and a roll-forward is still seen;
  - `process.manual-start-in-drain` (wp, wave 3): a listener's manual `start()` inside the drain stamps `ignited_by_event_id` = the fact id, and "a later `#[StartsOn]` ignition of that class by the same fact still ignites once".
  
  In 0.6 data, a manual start inside a drain and a `#[StartsOn]` ignition write identical rows (same class, same `ignited_by_event_id`, `source = 'event'`, no key). A plain `ignited_by_event_id` check would therefore make N's own manual start block the later ignition. With `start_path`, the check counts only rows a 0.6 copy wrote (`start_path IS NULL`). N's manual rows never block, and N's ignitions are gated by `UNIQUE (process_class, ignition_key)`.
- **Compatibility.** Nullable, no default needed (R5). A 0.6 INSERT names no v8 column, so rows written after a rollback have NULL, which is exactly what the check needs. The v8 migration adds it with `ddd_add_column_if_missing`. No reader of 0.6 depends on it.
- **Register edit.** Section 8 wave 3 wp bullet, the v8 column list: add `long_processes.start_path` (nullable).
