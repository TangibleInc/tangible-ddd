---
status: accepted (2026-07-04)
---

# Integration listeners are consumer-owned classes

The mapping from an integration event to the command it triggers lives in the consuming code as an `IntegrationListener` class, never on the event. A `to_command()` on the event was rejected: an event must not prescribe its response, one event may have many listeners, and a consumer in another plugin cannot edit the publisher's class. Closure listeners were rejected as the public form for four reasons: topology tooling can't see them, error logs only name them as `Closure@bootstrap.php:47`, they get no dependency injection, and they depend on manual wiring order. The class form can be enumerated, so the dashboard can draw event-to-command edges and errors name the policy. The procedural `integration_listener()` stays as the single internal ceremony the base class calls, not as a documented path. Rule: [DDD-M3](../modeling-rules.md).
