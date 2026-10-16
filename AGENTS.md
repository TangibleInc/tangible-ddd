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

## Code style

Naming follows DDD-N1 to DDD-N4 in [docs/modeling-rules.md](docs/modeling-rules.md#naming).
Formatting in this repository is informal: no formatter runs, and review
checks it. Use it in new code and in the lines you change; do not reformat
code you are not otherwise touching.

```php
final class Example implements IExample {

  public function award(PointCount $points, ?string $reason = null): void {
    if( !$points->is_positive() ) {
      throw new \InvalidArgumentException('Points must be positive');
    }
    foreach( $this->ledgers as $ledger ) {
      $ledger->record($points, $reason ?? '');
    }
    $this->total = (int) $this->total + $points->get_value();
    $this->label = $this->name . ': ' . $this->total;
  }
}
```

- Two spaces to indent. Braces on the same line, for classes and functions as
  well as control structures.
- No space between a control keyword and its parenthesis: `if(`, `elseif(`,
  `foreach(`, `for(`, `while(`, `switch(`, `catch(`, `match(`.
- One space inside a control structure's parentheses: `if( $x )`,
  `foreach( $items as $item )`. Calls and declarations have none:
  `award($points, $reason)`, `function award(int $points)`, `new Ledger($id)`.
- `!$x`, with no space. `(int) $x`. `$a . $b`. `): Type`, with no space before
  the colon.
- `[]`, never `array()`; `elseif`, never `else if`; `===` and `!==` only.

Much of the existing code predates this and uses `if ($x)`. The dashboard
(`ddd-wordpress/Admin/Dashboard/`) also has four-space indentation, braces on
the next line, and `camelCase` methods; bring a dashboard file to this style
and to DDD-N1 (it wraps `wpdb`, so DDD-N2 points there) when you change it
substantially.

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
