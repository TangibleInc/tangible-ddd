# Tangible DDD

Tangible DDD is a Domain-Driven Design runtime for WordPress plugins. It gives
each consumer plugin a command/query boundary, transactional event publication,
durable asynchronous delivery, causal tracing, and long-running orchestration
without making consumers share one database identity.

The current documentation describes the 0.6.x line. Check the installed
package version and source before applying examples to an older consumer.

## Requirements

- PHP 8.1 or newer
- WordPress
- Composer
- Action Scheduler
- A Symfony Dependency Injection container for each top-level consumer

## Quick start

```bash
composer require tangible/ddd:^0.6.2
wp ddd init --prefix=acme_orders --namespace='Acme\Orders'
```

Run `wp ddd init` from the consumer plugin directory. It creates the supported
DI and table scaffolding without modifying the plugin entry file; the command
prints the small bootstrap snippet to add there. Existing generated files are
preserved unless `--force` is supplied.

See [Wiring a consumer](docs/wiring-a-consumer.md) for the complete container,
bootstrap, migration, and deployment contract.

## Runtime model

- Commands enter the command bus. Its middleware owns correlation, audit,
  transaction, domain-event publication, and terminal handler execution.
- Queries use a read-only bus without the command transaction and audit
  bracket.
- Domain events are synchronous and remain inside the originating unit of
  work. Integration events cross a consistency boundary through the
  transactional outbox and Action Scheduler.
- Commands and queries may use separate handlers or explicitly opt into
  self-handling. Commands do not dispatch other commands.
- `BehaviourWorkflow` runs configurable, repeatable behaviour routines over
  work items. `LongProcess` models developer-authored business lifecycles that
  can schedule, suspend, await integration events, resume, and compensate.
- Long-process definitions are compiled into `LongProcessCatalog`, so dumped
  production containers have the same process discovery as development
  containers.
- Correlation and causation metadata connect commands, integration events,
  process wakes, and workflow work into a trace across consumer plugins.
- Declared aggregate touches create a rebuildable Biography read model without
  making the touches table a write-side authority.

## Consumer ownership

Every plugin may bundle its own Composer copy. Each copy registers at
`plugins_loaded:0`; at priority `1`, the loader initializes only the newest
registered version. Top-level consumers then boot against that winning copy and
retain their own namespace root, table prefix, container, and storage.

A consumer module is a strict namespace descendant that contributes commands,
queries, listeners, and long processes through a separate compiled container
while sharing its host consumer's runtime identity. A sidecar plugin is one way
to package such a module. Modules do not become additional dashboard consumers
and do not mutate the host container. See
[Consumer modules](docs/consumer-modules.md).

## Consumer-scoped storage

For a configured prefix such as `acme_orders`, the framework maintains seven
WordPress tables:

- `acme_orders_integration_outbox`
- `acme_orders_integration_dlq`
- `acme_orders_long_processes`
- `acme_orders_command_audit`
- `acme_orders_touches`
- `acme_orders_behaviour_workflows`
- `acme_orders_behaviour_workflow_items`

The real table names also include the site's WordPress table prefix. Retention
and export policy can be chosen per consumer; cross-plugin traces are assembled
from propagated correlation metadata rather than a shared write table.

## Dashboard

The winning framework copy registers **Tangible DDD** at the
`tangible-dddash` admin page. The dashboard discovers top-level consumers and
reads each consumer's own audit, outbox, process, workflow, and trace data. Its
live view uses WordPress Heartbeat to reveal new trace pieces as workers finish.

## Documentation

- [Documentation map](docs/README.md)
- [Wiring a consumer](docs/wiring-a-consumer.md)
- [Consumer modules](docs/consumer-modules.md)
- [Release and migration ledger](docs/migration-0.2-to-0.3.md)
- [Canonical agent skill](.claude/skills/tangible-ddd/SKILL.md)

Historical specs and plans are retained for design provenance and are clearly
classified in the documentation map. Current source and tests win whenever a
historical record disagrees with the installed package.
