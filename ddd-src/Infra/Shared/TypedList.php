<?php

namespace TangibleDDD\Infra\Shared;

/*
 * TypedList moved to TangibleDDD\Domain\Shared in 0.6.7: it is a plain
 * collection, and domain code may not depend on infrastructure (DDD-L1).
 *
 * The old name stays an alias of the new class, so consumers that have not
 * migrated keep working on a site where a newer copy of the framework wins.
 * Loading this file loads the new class, whose file registers the alias.
 */

class_exists(\TangibleDDD\Domain\Shared\TypedList::class);

if (\false) {
  /**
   * Declared only for static analysis and IDEs; never executed.
   *
   * @deprecated 0.6.7 Use \TangibleDDD\Domain\Shared\TypedList.
   */
  abstract class TypedList extends \TangibleDDD\Domain\Shared\TypedList {
  }
}
