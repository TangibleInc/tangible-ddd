<?php

namespace TangibleDDD\Tests\Unit\Infra;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Shared\TypedList;

/**
 * Concrete typed list for testing: a list of strings.
 */
class StringList extends TypedList {
  public function offsetGet($index): string {
    return $this->protected_get($index);
  }

  public function current(): string {
    return $this->protected_get($this->_position);
  }

  public function get_type(): string {
    return 'string';
  }
}

/**
 * Concrete typed list for testing: a list of stdClass.
 */
class ObjectList extends TypedList {
  public function offsetGet($index): \stdClass {
    return $this->protected_get($index);
  }

  public function current(): \stdClass {
    return $this->protected_get($this->_position);
  }

  public function get_type(): string {
    return \stdClass::class;
  }
}

class TypedListTest extends TestCase {

  // ─────────────────────────────────────────────────────────────
  // Construction & type enforcement
  // ─────────────────────────────────────────────────────────────

  public function test_construct_empty(): void {
    $list = new StringList();
    $this->assertTrue($list->empty());
    $this->assertSame(0, count($list));
  }

  public function test_construct_with_valid_items(): void {
    $list = new StringList(['a', 'b', 'c']);
    $this->assertSame(3, count($list));
    $this->assertFalse($list->empty());
  }

  public function test_rejects_wrong_type_on_construct(): void {
    $this->expectException(InvalidArgumentException::class);
    new StringList(['a', 42, 'b']);
  }

  public function test_rejects_wrong_type_on_set(): void {
    $list = new StringList();
    $this->expectException(InvalidArgumentException::class);
    $list[] = 42;
  }

  public function test_object_list_type_enforcement(): void {
    $list = new ObjectList([new \stdClass()]);
    $this->assertSame(1, count($list));

    $this->expectException(InvalidArgumentException::class);
    new ObjectList(['not an object']);
  }

  // ─────────────────────────────────────────────────────────────
  // Offset access
  // ─────────────────────────────────────────────────────────────

  public function test_offset_get(): void {
    $list = new StringList(['a', 'b', 'c']);
    $this->assertSame('a', $list[0]);
    $this->assertSame('b', $list[1]);
    $this->assertSame('c', $list[2]);
  }

  public function test_offset_exists(): void {
    $list = new StringList(['a', 'b']);
    $this->assertTrue(isset($list[0]));
    $this->assertTrue(isset($list[1]));
    $this->assertFalse(isset($list[2]));
  }

  public function test_offset_set_append(): void {
    $list = new StringList();
    $list[] = 'first';
    $list[] = 'second';

    $this->assertSame(2, count($list));
    $this->assertSame('first', $list[0]);
    $this->assertSame('second', $list[1]);
  }

  public function test_offset_unset(): void {
    $list = new StringList(['a', 'b', 'c']);
    unset($list[1]);

    $this->assertSame(2, count($list));
    $this->assertFalse(isset($list[1]));
  }

  // ─────────────────────────────────────────────────────────────
  // Iteration
  // ─────────────────────────────────────────────────────────────

  public function test_foreach_iteration(): void {
    $list = new StringList(['x', 'y', 'z']);
    $result = [];

    foreach ($list as $key => $value) {
      $result[$key] = $value;
    }

    $this->assertSame([0 => 'x', 1 => 'y', 2 => 'z'], $result);
  }

  public function test_rewind_resets_position(): void {
    $list = new StringList(['a', 'b']);

    // Iterate once
    $first_pass = [];
    foreach ($list as $v) $first_pass[] = $v;

    // Iterate again
    $second_pass = [];
    foreach ($list as $v) $second_pass[] = $v;

    $this->assertSame($first_pass, $second_pass);
  }

  // ─────────────────────────────────────────────────────────────
  // SeekableIterator
  // ─────────────────────────────────────────────────────────────

  public function test_seek_valid_position(): void {
    $list = new StringList(['a', 'b', 'c']);
    $list->seek(2);
    $this->assertSame('c', $list->current());
  }

  public function test_seek_invalid_position(): void {
    $list = new StringList(['a', 'b']);
    $this->expectException(OutOfBoundsException::class);
    $list->seek(5);
  }

  // ─────────────────────────────────────────────────────────────
  // Filter
  // ─────────────────────────────────────────────────────────────

  public function test_filter_in_place(): void {
    $list = new StringList(['apple', 'banana', 'avocado', 'cherry']);
    $list->filter(fn($s) => str_starts_with($s, 'a'));

    $this->assertSame(2, count($list));
  }

  public function test_filter_with_clone(): void {
    $list = new StringList(['apple', 'banana', 'avocado', 'cherry']);
    $filtered = $list->filter(fn($s) => str_starts_with($s, 'a'), clone: true);

    // Original unchanged
    $this->assertSame(4, count($list));
    // Filtered is new
    $this->assertSame(2, count($filtered));
  }

  public function test_filter_empty_result(): void {
    $list = new StringList(['a', 'b']);
    $filtered = $list->filter(fn($s) => $s === 'z', clone: true);

    $this->assertTrue($filtered->empty());
  }

  // ─────────────────────────────────────────────────────────────
  // Clone, of, last, keys
  // ─────────────────────────────────────────────────────────────

  public function test_clone_returns_new_instance(): void {
    $list = new StringList(['a', 'b']);
    $cloned = $list->clone();

    $this->assertSame(2, count($cloned));
    $this->assertNotSame($list, $cloned);
  }

  public function test_of_static_constructor(): void {
    $list = StringList::of(['hello', 'world']);
    $this->assertSame(2, count($list));
    $this->assertSame('hello', $list[0]);
  }

  public function test_last(): void {
    $list = new StringList(['first', 'middle', 'last']);
    $this->assertSame('last', $list->last());
  }

  public function test_last_empty_returns_null(): void {
    $list = new StringList();
    $this->assertNull($list->last());
  }

  public function test_keys(): void {
    $list = new StringList(['a', 'b', 'c']);
    $this->assertSame([0, 1, 2], $list->keys());
  }

  // ─────────────────────────────────────────────────────────────
  // JSON serialization
  // ─────────────────────────────────────────────────────────────

  public function test_json_serialize(): void {
    $list = new StringList(['a', 'b']);
    $json = json_encode($list);
    $this->assertSame('["a","b"]', $json);
  }

  public function test_toString(): void {
    $list = new StringList(['a', 'b', 'c']);
    $str = (string) $list;
    $this->assertSame('["a","b","c"]', $str);
  }

  public function test_toString_truncates_at_five(): void {
    $items = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
    $list = new StringList($items);
    $str = (string) $list;
    // Only first 5
    $this->assertSame('["a","b","c","d","e"]', $str);
  }

  // ─────────────────────────────────────────────────────────────
  // ksort
  // ─────────────────────────────────────────────────────────────

  public function test_ksort(): void {
    $list = new StringList();
    $list[5] = 'c';
    $list[1] = 'a';
    $list[3] = 'b';

    $list->ksort();
    $this->assertSame([1, 3, 5], $list->keys());
  }

  // ─────────────────────────────────────────────────────────────
  // Raw access
  // ─────────────────────────────────────────────────────────────

  public function test_raw_returns_reference(): void {
    $list = new StringList(['a', 'b']);
    $raw = &$list->raw();

    $this->assertSame(['a', 'b'], $raw);

    // Modify through reference
    $raw[] = 'c';
    $this->assertSame(3, count($list));
  }
}
