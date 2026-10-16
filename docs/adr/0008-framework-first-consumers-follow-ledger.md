---
status: accepted (owner ruling, 2026-07-19)
---

# The framework moves first; consumers follow through the ledger

A framework release does not wait for its consumers to migrate. Each version records what it demands of consumers in the [release ledger](../migration-0.2-to-0.3.md), and each consumer evolves on its own schedule by working through the ledger entries between its pinned version and the target. The cost lands on deploys, not releases. Because the loader runs the newest installed copy process-wide, a box must not receive a framework version that a co-hosted consumer has not yet migrated to. Deploy notes in the ledger carry these constraints.
