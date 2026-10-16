---
status: accepted (owner ruling, 2026-07-19)
---

# The trace kind set is closed at three

Trace nodes are Acts, Facts and Trajectories (`Kind`), and nothing else. Adding a kind takes a ruling; pressure for a fourth will recur, and this is the answer. Prior rulings that kept the set closed: BehaviourWorkflows are overlays on trajectories, not a kind; agents annotate roots and are not nodes; process-in-process is spelled with existing kinds (parent step → Act → Fact → `#[StartsOn]` child), so every hop is audited. Full reasoning: [0.3 spec §12](../0.3-trace-context.md) (historical document; the ruling is current).
