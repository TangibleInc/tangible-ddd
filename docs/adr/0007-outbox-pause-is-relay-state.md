---
status: accepted (2026-07-05)
---

# Pausing the outbox is relay state, never a row outcome

An outbox pause is a **hold** `{holder, selector, until}` on the relay. It is never a status written onto messages. While a hold is active, matching rows stay `pending` and are left out of the feed. On release they drain normally: nothing is dropped, nothing goes to the dead-letter queue, no attempts are burned. Holds are multi-holder and per-consumer, and are either indefinite (`until = -1`) or self-expiring. Writing "paused" onto rows was rejected because a lifecycle concern would leak into message state and releasing would mean rewriting rows. Design record: [outbox pause design](../outbox-pause-design.md).
