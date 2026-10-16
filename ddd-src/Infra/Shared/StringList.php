<?php

namespace TangibleDDD\Infra\Shared;

/*
 * StringList moved to TangibleDDD\Domain\Shared in 0.6.7; the old name
 * stays an alias. See TypedList.php in this directory.
 */

class_exists(\TangibleDDD\Domain\Shared\StringList::class);

if (\false) {
  /**
   * Declared only for static analysis and IDEs; never executed.
   *
   * @deprecated 0.6.7 Use \TangibleDDD\Domain\Shared\StringList.
   */
  final class StringList extends \TangibleDDD\Domain\Shared\TypedList {
  }
}
