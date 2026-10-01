# Wave 4: packaging-4 change requests

Author: packaging (branch `wave4/packaging-4`). This round changes no ratified interface, frozen FQCN or persisted string. It makes no contract change, additive or otherwise. The entries below are conventions this branch introduces and requests to other owners.

## PK4-1: the 7.3 hook of `run.sh compat` (convention, for wp)

- **What.** `tests/harness/run.sh compat` runs register 7.3 as the WordPress suite at `tests/Integration/Rollback/phpunit.xml` of the ref under test. It runs inside the WP integration bootstrap, after `install-tables.php`, on a fresh database. phpunit gets `--fail-on-skipped --fail-on-incomplete`, the same way `conformance-wp` runs its suite. When the file is absent, compat fails with a message naming it. Absence is not a skip.
- **Request (wp).** Put the 7.3 rollback fixtures under `tests/Integration/Rollback/` with that `phpunit.xml`. That covers the pending-row fixtures, the N-only artifacts and the three sequence fixtures. Starting fresh `php` children for the multi-process sequences works like `tests/Integration/Conformance/bin/fresh.php`. If the fixtures need a legacy copy (L-0.6.6, L-0.6.2) as the rollback winner, say how you want it supplied. The loader driver can export tags into the run, as `lib/loader.sh` does with `loader_copy`.

## PK4-2: loader judge and probe fields (convention)

`tests/Loader/fixtures/probe.php` now also prints these fields:

- `compiled`: per compiled-container fixture, the services resolved, errors and class mismatches;
- `ddd_class_copies` and `ddd_class_samples`: a census of every declared `TangibleDDD\` symbol by copy;
- `autoloaders`: the SPL chain.

`tests/Loader/assert-case.php` accepts four new expectations: `compiled`, `single_origin`, `jetpack` and `registered_versions`. They are packaging-owned test internals, listed here so a later author of loader cases knows them.

## PK4-3: compiled-container fixtures (provenance)

`tests/Loader/fixtures/compiled/{lms-0_12_0,quiz-0_7_0,certificates-0_3_1}` were generated once by `tests/Loader/bin/extract-compiled-container.php` from these release zips:

- `tangible-lms-0.12.0.zip` and `tangible-quiz-0.7.0.zip` (TangibleInc/lms-monorepo releases `lms@0.12.0` and `quiz@0.7.0`);
- `tangible-certificates-0.3.1.zip` (TangibleInc/tangible-certificates `v0.3.1`).

Each manifest records the sha256 of the source `CompiledContainer.php`. The zips are not committed. To regenerate, download them and run the extractor against `var/container/CompiledContainer.php` of each.

## Requests to other owners

- **wp (B8, carried from wave 2).** `Infra\Config::version()` and `AdminPage::enqueue()` still read `TANGIBLE_DDD_VERSION`, which is first-defined-wins. The loader run prints this as INFO in every case where an older copy loads first, including the new `load.jetpack-mixed` and `load.compiled-containers` cases. The register's 7.2 pass condition "dashboard version = `winner()`" therefore still holds only as INFO.
- **wp (carried from wave 2).** 13 procedural forwarding shims are still under `ddd-wordpress/`: everything except `self/index.php`. They still ship in the artifact (`git archive` lists them), although register 1.1 says "nothing else under `ddd-wordpress/`". The winner never loads them. Delete them, or say why they stay.
- **wp (register 7.4 and B24).** Two refusals in the register are not implemented on the integration branch:
  - ddd-wp does not check that its sibling `ddd-core` has the same version by path before initialising (B D-3).
  - ddd-wp boot does not refuse `TangibleDDD\Defaults\Pdo` under WordPress.

  `CHANGELOG.md` lists both combinations as unsupported without claiming a refusal. If they are to be enforced, the boot check belongs in `packages/ddd-wp/wordpress/hooks.php` (wp). Packaging can add the matching loader fixture.
- **coordinator (split mirrors).** A split mirror carries its package's `tests/` directory: splitsh-lite splits the tree, and the root `.gitattributes` does not apply inside a mirror. If Composer dist archives of `tangible/ddd-core` and `tangible/ddd-symfony` should leave their tests out, each package needs its own `.gitattributes` (`/tests export-ignore`). Those files sit under `packages/*/`, which belongs to core and symfony.
- **coordinator (CI).** `run.sh compat` is not in `extraction.yml`, because its 7.3 section fails until PK4-1 lands. Its static sections (CR-PK-5 expiry and the release artifact) run in the `static` job. The 7.2 section is the existing `wp-loader` job, which now runs every 7.2 case. Add a compat job once 7.3 exists.
