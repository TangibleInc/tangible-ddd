<?php

namespace TangibleDDD\Infra\Persistence\Select;

use MakinaCorpus\QueryBuilder\Query\Select;
use TangibleDDD\Infra\Exceptions\IncorrectUsageException;

/**
 * Base class for MakinaCorpus QueryBuilder-based selects.
 *
 * Extend this class to create domain-specific select implementations.
 * The query is exposed for composition; items() must be called via a
 * repository that knows how to hydrate the results.
 *
 * @example Define a domain-specific select:
 * ```php
 * namespace MyPlugin\Infra\Persistence\Select;
 *
 * use TangibleDDD\Infra\Persistence\Select\QueryBuilderSelect;
 *
 * class EarningSelect extends QueryBuilderSelect implements IEarningSelect {
 *   // Hydration happens in repository
 * }
 * ```
 *
 * @example Repository creates and hydrates selects:
 * ```php
 * class EarningRepository {
 *   public function for_user(int $user_id): IEarningSelect {
 *     $query = $this->qb->select('wp_earnings', 'e')
 *       ->column('e.*')
 *       ->where('user_id', $user_id);
 *     return new EarningSelect($query);
 *   }
 *
 *   public function hydrate(IEarningSelect $select): array {
 *     $rows = $select->query->executeQuery()->fetchAllAssociative();
 *     return array_map(fn($row) => Earning::from_row($row), $rows);
 *   }
 * }
 * ```
 *
 * @example Service composes query before hydration:
 * ```php
 * class EarningService {
 *   public function get_recent_for_user(int $user_id, int $limit = 10): array {
 *     $select = $this->repo->for_user($user_id);
 *     $select->query
 *       ->orderBy('created_at', 'DESC')
 *       ->range($limit);
 *     return $this->repo->hydrate($select);
 *   }
 * }
 * ```
 *
 * @see https://github.com/makinacorpus/php-query-builder
 */
abstract class QueryBuilderSelect implements ISelect {

  public function __construct(
    public readonly Select $query,
  ) {}

  public function is_hydrated(): bool {
    return false;
  }

  /**
   * @throws IncorrectUsageException Always - use repository hydrate() instead
   */
  public function items(): array {
    throw new IncorrectUsageException(
      'QueryBuilderSelect cannot hydrate itself. ' .
      'Pass the select to a repository hydrate() method that knows the domain types.'
    );
  }
}
