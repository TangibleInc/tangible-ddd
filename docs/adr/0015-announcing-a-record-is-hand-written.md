---
status: accepted (owner rulings, 2026-07-04)
---

# A domain event announces its record by hand; the return type is the declaration

A rich domain event reaches the wire through a hand-written `IAnnouncesIntegration::to_integration()`. Deriving it automatically was proposed four times and rejected each time: deciding which raise-time truths get published, and under what published names (`earning` becomes `earning_id`), is API design that neither class's structure contains. The method's narrowed return type is the only declaration of which twin is announced; a separate `integration_class()` member was rejected as a second source of truth that drifts. The interface is named for announcing, meaning it declares that a record will exist. It is not named for publishing or converting: `IIntegrationConvertible`, `IHasIntegrationContract` and `IIntegrationSource` were rejected, the last also because of its event-sourcing meaning and a clash with datastream's `IEventSource`. Twins live in an `Integration\` subnamespace under the same short name as their domain event, and only twins live there; self-publishers stay in the parent namespace. `Contracts\` was rejected because in PHP it means interfaces. Related: [0003](0003-integration-event-severed-from-domain-event.md).
