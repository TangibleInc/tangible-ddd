---
status: proposed (awaiting owner ruling)
---

# Continuations must carry causation across a scheduler hop

When a workflow re-enters through an Action Scheduler action, the envelope keeps the correlation but loses the event id. The continuation pass then has no cause and shows up as a depth-0 orphan inside its own story. The current workaround is [reschedule-via-fact](../eventing-lanes.md): announce a fact and let a listener translate it into the next command, so the hop is a normal causal edge. Undecided: whether the envelope itself should carry the cause across scheduler hops, which would make raw scheduling causally safe, or whether reschedule-via-fact stays the only legal continuation.
