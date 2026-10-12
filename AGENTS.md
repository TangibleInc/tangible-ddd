# Working in tangible-ddd

This file is for agents changing the framework itself. Agents working in a
project that *uses* the framework read [docs/agents.md](docs/agents.md) instead.

## Read first

- [docs/README.md](docs/README.md): which documents are CURRENT, HISTORICAL,
  or DESIGN ONLY, and which kind of "why" lives where. Source and tests
  outrank every document.
- [GLOSSARY.md](GLOSSARY.md): use these terms in code, docs, and commit
  messages; never the names listed under _Avoid_.
- [docs/adr/](docs/adr/): decisions already made. Do not re-propose an
  alternative an ADR has closed; if you think one is wrong, say so and cite it.
- [docs/modeling-rules.md](docs/modeling-rules.md): the rules, by ID.

## Checks

- `vendor/bin/phpunit`: unit suite (what CI runs).
- `vendor/bin/phpunit -c phpunit.integration.xml`: integration suite.
- `composer deadcode`: PHPStan dead-code pass.

## Documentation

- A new term or a settled decision goes into `GLOSSARY.md` or a new ADR in
  the same change that introduces it.
- Specs are written on the feature branch under `docs/specs/`. Before the
  PR merges, move their lasting content into ADRs, the glossary, or current
  docs, then delete the spec in that PR. Git history keeps it.
- Implementation plans and subagent reports are never committed; they live
  in the gitignored `.superpowers/`.
- Anything committed must make sense to a reader who has only this repo.
