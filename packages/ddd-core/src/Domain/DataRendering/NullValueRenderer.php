<?php

namespace TangibleDDD\Domain\DataRendering;

use TangibleDDD\Domain\Shared\IValueRenderer;

use stdClass;

/**
 * No-op renderer that returns values unchanged.
 */
class NullValueRenderer implements IValueRenderer {

  public function render_data(stdClass|array|null $data): stdClass|array {
    return $data ?? new stdClass();
  }
}
