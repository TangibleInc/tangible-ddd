---
status: accepted (approved in the documentation reset, 2026-07-21)
---

# Consumer-facing documents use conventional terms; the trace ontology is internal

Guidance written for consumer developers and their agents (the wiring guide, module guide, design interview, agent skill, modeling rules) says command execution, domain event, integration event, correlation and long-running process. Act, Fact, Trajectory and Moment appear there only when explaining the `Kind` API or a causal-storage field. Consumers model their domain in conventional DDD terms; the ontology is the trace's vocabulary, and making it headline vocabulary adds a second language with no modeling benefit. Framework-internal material (ADRs, the trace spec, the dashboard, the trace section of [GLOSSARY.md](../../GLOSSARY.md)) uses the ontology freely.
