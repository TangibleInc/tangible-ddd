---
status: accepted (owner ruling, 2026-07-10)
---

# Integration payloads are named, keyed by constructor parameter

Since 0.2.0 an integration event crosses the wire as one associative array keyed by its constructor parameter names, and hook callbacks take `function (array $payload)`. Positional arguments are not coming back. Outbox rows outlive the deploy that wrote them: positional drift silently corrupts fields, while named keys evolve safely. Named payloads also end WordPress `add_action` arity truncation (arity is 1 forever), make payload JSON queryable by people, the dashboard and SQL (dead-letter searches match on `"destination_id":N`), and are what the constructor-as-schema round trip (`from_payload`) needs. The accepted cost is stringly `$payload['key']` access at raw hook seams. That stays rare because the paved path is an `IntegrationListener` receiving a typed, hydrated event. Rule: [DDD-E4](../modeling-rules.md).
