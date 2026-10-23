<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Infra\Consumers\ConsumerHandle;

/**
 * Settles the async work that is already due, now, instead of waiting for
 * WP-Cron and Action Scheduler to come around.
 *
 * A pass relays one outbox batch for every consumer, then runs the due,
 * unclaimed, pending Action Scheduler actions in the consumers' own groups
 * (`as_group()`'s and the outbox queue's `<prefix>-…`: outbox delivery,
 * process steps, the consumer's recurring sweeps). Delivering one event can enqueue the next, so passes repeat
 * until one does nothing or the pass budget runs out.
 *
 * Only due work runs: rows under a hold, rows and actions scheduled for
 * later, and rows waiting out a retry backoff stay where they are. Settling
 * runs the same code the relay and the queue runner would; it only removes
 * the wait. Used by end-to-end tests and by operators via `wp ddd settle`.
 */
final class DueWorkSettler {

  public const DEFAULT_MAX_PASSES = 25;

  /** Action Scheduler log context for the actions settling runs. */
  public const CONTEXT = 'tangible-ddd settle';

  /** @var callable(ConsumerHandle): int */
  private $relay_outbox;

  /** @var callable(string[]): int[] */
  private $find_due_actions;

  /** @var callable(int): void */
  private $run_action;

  /**
   * @param array<string, ConsumerHandle> $consumers prefix => handle
   * @param (callable(ConsumerHandle): int)|null $relay_outbox relays one
   *   batch for a consumer and returns the rows it handled
   * @param (callable(string[]): int[])|null $find_due_actions due pending
   *   action ids whose group starts with one of the given prefixes
   * @param (callable(int): void)|null $run_action runs one action by id
   */
  public function __construct(
    private readonly array $consumers,
    ?callable $relay_outbox = null,
    ?callable $find_due_actions = null,
    ?callable $run_action = null,
  ) {
    $this->relay_outbox = $relay_outbox ?? self::relay_with_processor(...);
    $this->find_due_actions = $find_due_actions ?? self::due_scheduler_actions(...);
    $this->run_action = $run_action ?? self::run_scheduler_action(...);
  }

  public function settle(int $max_passes = self::DEFAULT_MAX_PASSES): SettleResult {
    // Two spellings of a consumer's groups: as_group() (which may turn the
    // prefix's underscores into hyphens) and the outbox queue the outbox
    // repositories write, the raw prefix plus '-outbox'.
    $group_prefixes = [];
    foreach( $this->consumers as $handle ) {
      $group_prefixes[] = $handle->config()->as_group('');
      $group_prefixes[] = $handle->prefix() . '-';
    }
    $group_prefixes = array_values(array_unique($group_prefixes));

    $passes = 0;
    $rows = 0;
    $actions = 0;
    $settled = false;

    while( $passes < $max_passes ) {
      $passes++;
      $pass_work = 0;

      foreach( $this->consumers as $handle ) {
        $relayed = ($this->relay_outbox)($handle);
        $rows += $relayed;
        $pass_work += $relayed;
      }

      foreach( ($this->find_due_actions)($group_prefixes) as $action_id ) {
        ($this->run_action)($action_id);
        $actions++;
        $pass_work++;
      }

      if( $pass_work === 0 ) {
        $settled = true;
        break;
      }
    }

    return new SettleResult($passes, $rows, $actions, $settled);
  }

  private static function relay_with_processor(ConsumerHandle $handle): int {
    if( function_exists('TangibleDDD\\WordPress\\outbox_enabled')
      && !\TangibleDDD\WordPress\outbox_enabled($handle->config()) ) {
      return 0;
    }

    $container = $handle->container();
    if( method_exists($container, 'has') && !$container->has(OutboxProcessor::class) ) {
      return 0;
    }

    return $container->get(OutboxProcessor::class)->process_batch()->total;
  }

  /**
   * @param string[] $group_prefixes
   *
   * @return int[]
   */
  private static function due_scheduler_actions(array $group_prefixes): array {
    if( !function_exists('as_get_scheduled_actions') || $group_prefixes === [] ) {
      return [];
    }

    $actions = as_get_scheduled_actions([
      'status' => \ActionScheduler_Store::STATUS_PENDING,
      'claimed' => false,
      'date' => time(),
      'date_compare' => '<=',
      'per_page' => 100,
      'orderby' => 'date',
      'order' => 'ASC',
    ]);

    $due = [];
    foreach( $actions as $action_id => $action ) {
      $group = (string) $action->get_group();
      foreach( $group_prefixes as $prefix ) {
        if( $prefix !== '' && str_starts_with($group, $prefix) ) {
          $due[] = (int) $action_id;
          break;
        }
      }
    }

    return $due;
  }

  private static function run_scheduler_action(int $action_id): void {
    \ActionScheduler::runner()->process_action($action_id, self::CONTEXT);
  }
}
