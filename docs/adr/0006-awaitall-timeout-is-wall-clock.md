---
status: accepted (2026-07-04)
---

# AwaitAll timeouts are pure wall-clock time

An `AwaitAll` must declare a timeout, and that timeout counts wall-clock time, not "time the outbox was running". A pause-aware snooze was considered and rejected: it couples process timing to relay state and makes deadlines unpredictable. The ops caveat that comes with this is that a long outbox hold can expire awaits whose facts are still queued. Operators releasing a long hold should expect `on_timeout` outcomes. In the same design, a `key_by` static extractor replaced the `Gatherable` interface, so events stay ignorant of who awaits them, and the class attribute `#[Awaits]` replaced per-saga YAML. Details: [outbox pause design §6](../outbox-pause-design.md).
