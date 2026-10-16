---
status: accepted (owner ruling, 2026-07-06)
---

# IntegrationEvent is severed from DomainEvent

`IntegrationEvent` and `DomainEvent` both extend a shared `Event` root, and neither extends the other. Because `record()` only takes a domain event, a pure integration record cannot be raised: the type system enforces "one fact, one raise site". An event reaches the wire in one of two ways. A born-scalar domain event can be a **self-publisher** (it implements `IIntegrationEvent` + `IAnnouncesIntegration` and announces itself). A rich domain event returns a scalar **twin** from `to_integration()`, and the twin is derived and never raised. An earlier same-day design used a separate announcement interface on a shared hierarchy; it was dropped because it left twins raisable. Current forms: [agent skill](../../.claude/skills/tangible-ddd/SKILL.md), [eventing lanes](../eventing-lanes.md).
