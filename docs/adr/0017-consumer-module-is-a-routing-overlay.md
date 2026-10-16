---
status: accepted (approved for 0.6.2, 2026-07-21)
---

# A consumer module is a routing overlay, not a second consumer

Separately deployed code that extends a consumer attaches as a namespace-routing overlay. It uses the host's exact `IDDDConfig` with its own container, and never appears in `ConsumerRegistry::all()`. Two alternatives were rejected. Registering a child consumer doesn't work because consumers are keyed by prefix: reusing the host prefix replaces the host, and a new prefix invents tables, hooks, migrations and a dashboard identity. Mutating the host container doesn't work because dumped production containers expose no definition API, the build would depend on plugin load order, and debug would diverge from release. Ownership is only ever explicit: a module names its host and a strictly nested namespace root. Nothing is inferred from a broad namespace, an active plugin or a table name, so a plugin such as Notifications never gains DDD attribution by proximity. A stateful host service (transaction, unit of work, outbox, runner) is imported as the host's actual object or not at all; a lookalike rebuilt from `config_for()` is not a substitute. Still open: a distinct `ConsumerModuleHandle` type, whether host service IDs become a framework contract or a per-module manifest, and how the dashboard groups modules. Contract: [consumer modules](../consumer-modules.md).
