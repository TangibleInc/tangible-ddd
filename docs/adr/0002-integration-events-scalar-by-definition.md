---
status: accepted (owner ruling, 2026-07-04; enforcement amended 0.2.4)
---

# An integration event is scalar by definition

An `IntegrationEvent` holds only reversible values: scalars, backed enums, `DateTimeInterface`, and arrays of those. The class is its own wire schema and owns its codec. The strict scalariser throws `NonReversibleValue` on an entity, where it used to flatten lossily. This was a deliberate hard break: no legacy `BroadcastEvent` lane for fat payloads, because every consumer was in-house and the inventory showed fat payloads were never consumed. The original ruling held that enforcement comes from construction alone, with no config gate or conformance sweep. 0.2.4 (42df51b, 2026-07-18) amended that by adding `IntegrationConformance`, which is now the enforcer for [DDD-E4](../modeling-rules.md). Construction-time errors remain the first line of enforcement.
