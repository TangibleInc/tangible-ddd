---
status: accepted (owner design, 2026-07-04)
---

# Fan-in is owned by the awaiting process; the awaited never points back

When a process waits for N integration events, everything about the gathering lives on the process: the ids it minted, how to recognise an arrival (`key_by`, a static method reference on the process, serialized as two strings), the cardinality, and the timeout. The events and the coordinated entities know nothing about who awaits them. Rejected alternatives:
- an `origin_saga_id` on the domain entity, which makes the coordinated thing point back at its coordinator;
- external aggregators, tally tables and synthetic "group resolved" events, which are infrastructure working around a missing primitive;
- closure predicates, which a suspended process cannot serialize for resumption;
- a `Gatherable` interface on the event, since an event must not know its consumers;
- key extraction by property-name string, where a typo means the process silently never wakes.

The buffer stores arrived ids only; the step after the await re-reads outcomes from durable rows and judges the group. `AwaitAny`, k-of-n and multi-class mechanisms are deliberately not built until a use case needs one. Related: [0006](0006-awaitall-timeout-is-wall-clock.md).
