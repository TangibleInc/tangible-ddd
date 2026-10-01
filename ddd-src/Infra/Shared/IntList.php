<?php

namespace TangibleDDD\Infra\Shared;

/*
 * IntList moved to TangibleDDD\Domain\Shared in 0.6.7; the old name stays
 * an alias. See TypedList.php in this directory.
 */

class_exists(\TangibleDDD\Domain\Shared\IntList::class);

if (\false) {
  /**
   * Declared only for static analysis and IDEs; never executed.
   *
   * @deprecated 0.6.7 Use \TangibleDDD\Domain\Shared\IntList.
   */
  final class IntList extends \TangibleDDD\Domain\Shared\TypedList {
  }
}
