---
status: accepted (owner ruling, 2026-07-14)
---

# command_audit records Acts only; there is no unified "work" record

`command_audit` stays a pure command audit. We will not merge command and process rows into one "work record" observability surface. Every non-read goes through a command, so the command pass is the atomic unit of work. A trajectory does no work of its own: it leaps between commands over time. The process table joined to `command_audit` by causation and correlation already tells the whole story. A routing-level genus (a listener or step that may yield a command *or* start a process) is acceptable as a routing concept only. It must not become an audit or persistence concept, and must not be named "Work". Related: [0005](0005-kind-set-closed-at-three.md).
