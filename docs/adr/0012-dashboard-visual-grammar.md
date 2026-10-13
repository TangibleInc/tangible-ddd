---
status: accepted (owner rulings, 2026-06-29 and 2026-07-22)
---

# Dashboard visual grammar: kind is form, consumer is colour

In the dashboard, a node's **kind is shown by its shape** and its **owning consumer by its colour**. Command = solid bar, workflow = notched bar, trajectory = hatched span, fact = diamond port outlined by its raiser. Only status keeps semantic colour, and dead-letter red overrides any consumer accent. The older per-kind palette is retired. Measured widths appear only on command bars and reaction bars; everything else uses glyphs, tints and ordinal space, so listener segments are never time-scaled without instrumentation. Traces use the dense "ledger" layout: facts dock as ports after their raising command, and a cross-consumer handoff is drawn as a seam between rows. The theme is "Warm Blueprint" and the mark is the "Hexagon Causation" logo ([build outline](../dashboard/BUILD-OUTLINE.md)).
