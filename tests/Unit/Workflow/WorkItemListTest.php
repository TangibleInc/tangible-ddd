<?php

namespace TangibleDDD\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;

class WorkItemListTest extends TestCase {

  private function item(string $key, WorkItemStatus $status = WorkItemStatus::pending): WorkItem {
    return new WorkItem(null, 1, 0, 1, $key, $status);
  }

  // ─────────────────────────────────────────────────────────────
  // Construction & basics
  // ─────────────────────────────────────────────────────────────

  public function test_empty_list(): void {
    $list = new WorkItemList();
    $this->assertTrue($list->empty());
    $this->assertSame(0, count($list));
  }

  public function test_construct_with_items(): void {
    $list = new WorkItemList([$this->item('a'), $this->item('b')]);
    $this->assertFalse($list->empty());
    $this->assertSame(2, count($list));
  }

  public function test_rejects_non_work_item(): void {
    $this->expectException(\InvalidArgumentException::class);
    new WorkItemList([new \stdClass()]);
  }

  // ─────────────────────────────────────────────────────────────
  // Status filters
  // ─────────────────────────────────────────────────────────────

  public function test_pending_filter(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::pending),
      $this->item('b', WorkItemStatus::done),
      $this->item('c', WorkItemStatus::pending),
    ]);

    $pending = $list->pending();
    $this->assertSame(2, count($pending));
    $this->assertTrue($list->has_pending());
  }

  public function test_failed_filter(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::failed),
      $this->item('b', WorkItemStatus::done),
    ]);

    $failed = $list->failed();
    $this->assertSame(1, count($failed));
    $this->assertTrue($list->has_failed());
  }

  public function test_done_filter(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::done),
      $this->item('b', WorkItemStatus::done),
      $this->item('c', WorkItemStatus::pending),
    ]);

    $done = $list->done();
    $this->assertSame(2, count($done));
  }

  public function test_waiting_filter(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::waiting),
      $this->item('b', WorkItemStatus::done),
    ]);

    $waiting = $list->waiting();
    $this->assertSame(1, count($waiting));
    $this->assertTrue($list->has_waiting());
  }

  public function test_filters_return_new_list(): void {
    $list = new WorkItemList([$this->item('a'), $this->item('b')]);
    $pending = $list->pending();

    // Original list unchanged
    $this->assertSame(2, count($list));
    $this->assertSame(2, count($pending));
  }

  public function test_has_pending_false_when_none(): void {
    $list = new WorkItemList([$this->item('a', WorkItemStatus::done)]);
    $this->assertFalse($list->has_pending());
  }

  // ─────────────────────────────────────────────────────────────
  // Aggregate status
  // ─────────────────────────────────────────────────────────────

  public function test_aggregate_status_pending_highest_priority(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::done),
      $this->item('b', WorkItemStatus::pending),
      $this->item('c', WorkItemStatus::failed),
    ]);

    $this->assertSame(WorkItemStatus::pending, $list->aggregate_status());
  }

  public function test_aggregate_status_waiting_over_failed(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::waiting),
      $this->item('b', WorkItemStatus::failed),
      $this->item('c', WorkItemStatus::done),
    ]);

    $this->assertSame(WorkItemStatus::waiting, $list->aggregate_status());
  }

  public function test_aggregate_status_failed_over_done(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::done),
      $this->item('b', WorkItemStatus::failed),
    ]);

    $this->assertSame(WorkItemStatus::failed, $list->aggregate_status());
  }

  public function test_aggregate_status_done_when_all_done(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::done),
      $this->item('b', WorkItemStatus::done),
    ]);

    $this->assertSame(WorkItemStatus::done, $list->aggregate_status());
  }

  public function test_aggregate_status_skipped_treated_as_done(): void {
    $list = new WorkItemList([
      $this->item('a', WorkItemStatus::done),
      $this->item('b', WorkItemStatus::skipped),
    ]);

    // No pending, no waiting, no failed → done
    $this->assertSame(WorkItemStatus::done, $list->aggregate_status());
  }

  // ─────────────────────────────────────────────────────────────
  // Take & item_keys
  // ─────────────────────────────────────────────────────────────

  public function test_take_limits_items(): void {
    $list = new WorkItemList([
      $this->item('a'),
      $this->item('b'),
      $this->item('c'),
    ]);

    $taken = $list->take(2);
    $this->assertSame(2, count($taken));
  }

  public function test_take_zero_returns_one(): void {
    $list = new WorkItemList([$this->item('a'), $this->item('b')]);
    $taken = $list->take(0);
    // take(0) → max(1, 0) = 1
    $this->assertSame(1, count($taken));
  }

  public function test_take_more_than_available(): void {
    $list = new WorkItemList([$this->item('a')]);
    $taken = $list->take(10);
    $this->assertSame(1, count($taken));
  }

  public function test_item_keys(): void {
    $list = new WorkItemList([
      $this->item('user-1'),
      $this->item('user-2'),
      $this->item('user-3'),
    ]);

    $this->assertSame(['user-1', 'user-2', 'user-3'], $list->item_keys());
  }

  // ─────────────────────────────────────────────────────────────
  // Iteration
  // ─────────────────────────────────────────────────────────────

  public function test_iteration(): void {
    $items = [$this->item('a'), $this->item('b'), $this->item('c')];
    $list = new WorkItemList($items);

    $keys = [];
    foreach ($list as $item) {
      $keys[] = $item->item_key;
    }

    $this->assertSame(['a', 'b', 'c'], $keys);
  }

  public function test_offsetGet(): void {
    $list = new WorkItemList([$this->item('a'), $this->item('b')]);
    $this->assertSame('a', $list[0]->item_key);
    $this->assertSame('b', $list[1]->item_key);
  }
}
