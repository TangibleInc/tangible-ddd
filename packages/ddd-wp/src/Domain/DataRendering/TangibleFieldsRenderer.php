<?php

namespace TangibleDDD\Domain\DataRendering;

use RuntimeException;

/**
 * Renderer that uses Tangible Fields for template variable substitution.
 *
 * Requires the Tangible Fields plugin to be active.
 */
class TangibleFieldsRenderer extends AbstractValueRenderer {

  private array $context;

  /**
   * @param array $context Associative array of context variables for template substitution.
   * @throws RuntimeException If tangible_fields() is not available.
   */
  public function __construct(array $context = []) {
    if (!function_exists('tangible_fields')) {
      throw new RuntimeException(
        'TangibleFieldsRenderer requires the Tangible Fields plugin. ' .
        'Use NullValueRenderer if template rendering is not needed.'
      );
    }
    $this->context = $context;
  }

  protected function render_value(string $value): string {
    return tangible_fields()->render_value($value, [
      'context' => $this->context
    ]);
  }

  /**
   * Create a new renderer with additional context merged in.
   */
  public function with_context(array $additional_context): static {
    return new static(array_merge($this->context, $additional_context));
  }
}
