<?php

namespace TangibleDDD\Infra\Persistence\Shared;

/**
 * Interface for repositories that support paginated search.
 */
interface ISearchableRepository {

  /**
   * Search the repository with pagination.
   *
   * @param string|null $src Search term (implementation-specific)
   * @param array $ids Filter by specific IDs
   * @param bool $return_ids If true, return IDs only; if false, return full entities
   * @param int $page Page number (1-indexed)
   * @param int $per_page Items per page (-1 for all)
   * @param bool $only_published Filter to published items only (for WordPress post types)
   */
  public function search(
    ?string $src = null,
    array   $ids = [],
    bool    $return_ids = true,
    int     $page = 1,
    int     $per_page = -1,
    bool    $only_published = true
  ): RepositorySearchResult;
}
