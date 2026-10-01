<?php

namespace TangibleDDD\Domain\Shared;

use stdClass;

/**
 * Interface for rendering/transforming value object data.
 *
 * Implementations can perform field substitutions, template rendering,
 * or other transformations on raw JSON data before hydration.
 */
interface IValueRenderer {

  /**
   * Render/transform the given data.
   *
   * @param stdClass|array|null $data The raw data to render
   * @return stdClass|array The rendered data
   */
  public function render_data(stdClass|array|null $data): stdClass|array;
}
