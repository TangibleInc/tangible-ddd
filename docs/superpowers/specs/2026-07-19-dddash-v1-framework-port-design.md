# Tangible DDDash v1 Framework Port

## Objective

Move the behavior of `wp-content/mu-plugins/tangible-dddash.php` into
`tangible-ddd` as namespaced, testable framework code. The mu-plugin remains
unchanged as the behavioral reference. This pass preserves the current v1
product; it does not reinterpret the dashboard through the newer Act, Fact,
Trajectory ontology.

## Parity Contract

The framework dashboard keeps the existing:

- Tools page slug `tangible-dddash` and Warm Blueprint interface.
- REST namespace `tangible-ddd/v1` and all nine route behaviors.
- Consumer discovery, framework self-consumer, and orphaned table-set ghosts.
- Command Audit, Flow, Trace, Processes, Workflows, DLQ, and Outbox views.
- Heartbeat live tail, filtering, pagination, drawers, confirmations, and
  replay/discard/retry/purge actions.
- Response field names and normalization used by the existing JavaScript.

The monolithic implementation is the oracle when a behavior is ambiguous.

## Architecture

### Operational read side

Each current query becomes one class under
`TangibleDDD\WordPress\Admin\Dashboard\Query`. SQL remains consumer-scoped and
read-only. Queries depend on a small database port rather than the global
`$wpdb`, making result normalization and edge cases unit-testable without a
WordPress database.

`ConsumerCatalog` owns live registry consumers, the `tangible_ddd`
self-consumer, legacy discovery fallback, and ghost table discovery. A
prefix-only `IDDDConfig` implementation supports orphaned table sets.

### WordPress adapters

`Dashboard` is the composition root. It wires:

- `RestController` for route registration and request/response adaptation.
- `HeartbeatController` for the live-tail transport.
- `AdminPage` for menu registration, boot data, template, and assets.
- `ActionDispatcher` for the four existing framework command actions.

The HTML template, CSS, and JavaScript are separate assets. They remain vanilla
PHP/CSS/JS, with no build step and no runtime dependency on the mu-plugin.

### Legacy takeover

The mu-plugin loads first and may register the same hooks. The framework port
therefore takes over deliberately:

- REST routes are registered with WordPress's override flag.
- The old Tools submenu entry and page callback are removed before the
  framework page is registered.
- The framework heartbeat filter runs after the legacy filter and owns the
  final `tangible_ddd` payload.

This leaves the reference file executable on older framework copies while the
newest winning framework copy owns the dashboard when available.

## Error Behavior

Unknown consumers and actions retain their existing 400 responses. Operational
action failures retain a 500 response with the thrown message. Optional or
consumer-version-specific tables remain tolerant where v1 is tolerant; required
table failures remain visible rather than being silently converted into empty
success responses.

## Verification

1. Characterization tests pin catalog resolution, filters, pagination,
   normalization, trace assembly, and action dispatch.
2. Hook/route tests pin permissions, route names, override behavior, and legacy
   page takeover.
3. The existing unit suite and static analysis must remain green.
4. WordPress-backed REST checks compare framework responses with the reference
   against the same consumer data.
5. Browser checks cover the admin page at desktop and mobile widths, all views,
   live data, drawers, and action confirmations.

## V2 Extension Points

V1 keeps its array contracts, but the boundaries leave room for a portable v2:

- A host-neutral `Tracer` can gather fragments across multiple consumers and
  return typed Act, Fact, and Trajectory nodes.
- A biography query can read `{prefix}_touches` by aggregate and link its
  correlations to traces.
- WordPress remains one database/admin adapter rather than becoming part of the
  trace model.

No v2 route, response field, or UI behavior is introduced in this port.
