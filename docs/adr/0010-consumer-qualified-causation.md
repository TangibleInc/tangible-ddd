---
status: proposed (awaiting owner ruling)
---

# Causes must be qualified by consumer

`long_processes` ids are table-local integers, and a causation reference does not say which consumer's table it points into. When one consumer's process #48 dispatches a command whose fact starts another consumer's process #48, the trace resolves the wrong parent and draws a causal loop. `TraceStitcher` currently mitigates this with "a cause cannot postdate its effect", resolving local-first among eligible candidates, but that is a heuristic. The two options are a prefixed causation id (`lms:48`) or a separate `causation_consumer` column. Undecided.
