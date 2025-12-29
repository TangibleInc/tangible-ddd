<?php

namespace TangibleDDD\Infra\Persistence\Select;

/**
 * Interface for lazy/deferred query results.
 *
 * This pattern separates query building from execution, allowing:
 * - Repositories to return query objects instead of arrays
 * - Callers to compose/modify queries before execution
 * - Pagination, filtering, and sorting at the edges
 *
 * @example Consumer defines domain-specific select interface:
 * ```php
 * interface IEarningSelect extends ISelect {
 *   // Marker interface for type safety
 * }
 * ```
 *
 * @example Repository returns a select instead of array:
 * ```php
 * class EarningRepository {
 *   public function for_user(int $user_id): IEarningSelect {
 *     $query = $this->qb->select('earnings')
 *       ->where('user_id', $user_id);
 *     return new EarningSelect($query);
 *   }
 * }
 * ```
 *
 * @example Caller can further refine before hydrating:
 * ```php
 * $select = $repo->for_user($user_id);
 * $select->query->orderBy('created_at', 'DESC')->range(10);
 * $earnings = $select->items(); // Now executes and hydrates
 * ```
 */
interface ISelect {

  /**
   * Whether the query has been executed and results hydrated.
   */
  public function is_hydrated(): bool;

  /**
   * Execute the query and return hydrated domain objects.
   *
   * @return array Domain objects (entities, value objects, etc.)
   */
  #[\ReturnTypeWillChange]
  public function items(): array;
}
