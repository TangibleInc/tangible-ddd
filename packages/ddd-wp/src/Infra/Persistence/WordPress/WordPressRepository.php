<?php

namespace TangibleDDD\Infra\Persistence\WordPress;

use TangibleDDD\Domain\ValueObjects\EntityAttributes\BaseAssociatedEntityAttributes;
use TangibleDDD\Infra\Persistence\Shared\ISearchableRepository;
use TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository;
use TangibleDDD\Infra\Persistence\Shared\RepositorySearchResult;

/**
 * WordPress-backed repository helpers.
 *
 * Provides:
 * - WP_Query-powered search with pagination
 * - Convenience helpers for persisting associated entities in separate postmeta keys
 *
 * Concrete repositories are expected to implement `get_by_id()` via their own persistence,
 * and provide a `get_post_type()` for search.
 */
abstract class WordPressRepository extends PersistsAggregatesRepository implements ISearchableRepository {

  /**
   * Return the WordPress post type used for this repository.
   */
  abstract protected function get_post_type(): string;

  /**
   * Persist "associated entities" as separate post meta keys, one key per related id.
   *
   * @param int|string $post_id
   * @param array<BaseAssociatedEntityAttributes> $associated_entities
   * @param string $prefix Meta key prefix (e.g. "_assoc_")
   * @param bool $purge_metas If true, purge existing prefixed meta keys first
   */
  protected static function save_associated_entity_separate_metas(
    $post_id,
    array $associated_entities,
    string $prefix,
    bool $purge_metas = true
  ): void {
    if ($purge_metas) {
      static::purge_associated_entity_separate_metas($post_id, $prefix);
    }

    foreach ($associated_entities as $associated_entity) {
      update_post_meta(
        $post_id,
        "{$prefix}{$associated_entity->get_related_id()}",
        $associated_entity->to_json()
      );
    }
  }

  /**
   * Retrieve associated entity JSON values from post meta keys.
   *
   * Returns [related_id => json] where related_id is derived from the meta key suffix.
   */
  protected static function retrieve_associated_entity_from_metas($post_id, string $prefix): array {
    $all_meta = get_post_meta($post_id, '', true);

    $filtered_meta = array_filter($all_meta, function($key) use ($prefix) {
      return str_starts_with($key, $prefix);
    }, ARRAY_FILTER_USE_KEY);

    foreach ($filtered_meta as $key => $val) {
      $content_id = str_replace($prefix, '', $key);
      $filtered_meta[$content_id] = $val[0];
      unset($filtered_meta[$key]);
    }

    return $filtered_meta;
  }

  /**
   * Purge associated entity meta keys.
   */
  protected static function purge_associated_entity_separate_metas($post_id, string $prefix): void {
    $all_meta = get_post_meta($post_id, '', true);

    $filtered_meta = array_filter($all_meta, function($key) use ($prefix) {
      return str_starts_with($key, $prefix);
    }, ARRAY_FILTER_USE_KEY);

    foreach ($filtered_meta as $key => $val) {
      delete_post_meta($post_id, $key);
    }
  }

  public function search(
    ?string $src = null,
    array   $ids = [],
    bool    $return_ids = false,
    int     $page = 1,
    int     $per_page = -1,
    bool    $only_published = true
  ): RepositorySearchResult {

    $args = array(
      'post_type' => $this->get_post_type(),
      'post_status' => 'publish',
      'posts_per_page' => $per_page,
      'fields' => 'ids',
      'paged' => $page,
    );

    if (!$only_published) {
      $args['post_status'] = ['publish', 'draft', 'pending', 'private', 'future'];
    }

    // Add search term if provided
    if (!empty($src)) {
      $args['s'] = $src;
      $args['search_columns'] = ['post_content', 'post_name', 'post_title'];
    }

    // Add post ID filter if provided
    if (!empty($ids)) {
      $args['post__in'] = $ids;
    }

    $query = new \WP_Query($args);
    $result_ids = $query->posts;

    return new RepositorySearchResult(
      items: $return_ids ? $result_ids : array_map([$this, 'get_by_id'], $result_ids),
      total: (int) $query->found_posts,
      current_page: $page,
      total_pages: (int) $query->max_num_pages
    );
  }
}


