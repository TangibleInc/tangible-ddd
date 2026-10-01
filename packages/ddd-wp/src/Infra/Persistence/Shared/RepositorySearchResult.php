<?php

namespace TangibleDDD\Infra\Persistence\Shared;

/**
 * Result object for paginated repository searches.
 */
class RepositorySearchResult {

  public function __construct(
    /** @var array The items for the current page */
    public array $items,
    /** @var int Total number of items across all pages */
    public int   $total,
    /** @var int Current page number (1-indexed) */
    public int   $current_page,
    /** @var int Total number of pages */
    public int   $total_pages,
  ) {
  }

  /**
   * Check if there are more pages after the current one.
   */
  public function has_next_page(): bool {
    return $this->current_page < $this->total_pages;
  }

  /**
   * Check if there are pages before the current one.
   */
  public function has_previous_page(): bool {
    return $this->current_page > 1;
  }

  /**
   * Check if the result is empty.
   */
  public function is_empty(): bool {
    return empty($this->items);
  }
}
