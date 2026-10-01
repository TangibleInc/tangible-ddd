<?php
/**
 * Root compat/ alias map (register 1.2 and 1.3 R1; report B7).
 *
 * legacy FQCN => current FQCN. The winner's autoloader serves a legacy name
 * as class_alias of its target, so a consumer compiled against an older name
 * keeps resolving from the winner's distribution instead of falling through
 * to an older vendored copy. The target must resolve in the winner.
 *
 * Empty in 0.7.0: the extraction keeps every FQCN (R1-R5, 18 moves and 17
 * splits all keep their names). An entry is added only when a class is
 * renamed, and never instead of keeping a frozen FQCN.
 *
 * @return array<class-string, class-string>
 */

return [];
