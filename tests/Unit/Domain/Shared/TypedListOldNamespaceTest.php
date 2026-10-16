<?php

namespace TangibleDDD\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Shared\IntList;
use TangibleDDD\Domain\Shared\StringList;
use TangibleDDD\Domain\Shared\TypedList;

/**
 * The typed lists moved from Infra\Shared to Domain\Shared in 0.6.7. Only the
 * newest framework copy runs on a site, so plugins still using the old names
 * must keep working against it. Each test runs in its own process, because
 * the point is what happens depending on which name is loaded first.
 */
#[RunTestsInSeparateProcesses]
final class TypedListOldNamespaceTest extends TestCase {

  public function test_new_name_loaded_first_satisfies_the_old_name(): void {
    $ids = new IntList([1, 2, 3]);

    // Nothing has loaded the old file. The alias registered by the new
    // class's own file is what makes these hold.
    $this->assertInstanceOf('TangibleDDD\Infra\Shared\IntList', $ids);
    $this->assertInstanceOf('TangibleDDD\Infra\Shared\TypedList', $ids);

    $count = static fn(\TangibleDDD\Infra\Shared\IntList $list): int => count($list);
    $this->assertSame(3, $count($ids));
  }

  public function test_old_name_loaded_first_is_the_same_class(): void {
    $this->assertTrue(class_exists('TangibleDDD\Infra\Shared\StringList'));

    $old = new \ReflectionClass('TangibleDDD\Infra\Shared\StringList');
    $this->assertSame(StringList::class, $old->getName());

    $names = new \TangibleDDD\Infra\Shared\StringList(['a', 'b']);
    $this->assertInstanceOf(StringList::class, $names);
  }

  public function test_subclass_of_the_old_name_is_a_new_typed_list(): void {
    $list = new class(['legacy']) extends \TangibleDDD\Infra\Shared\TypedList {
      public function offsetGet($index): string {
        return $this->protected_get($index);
      }

      public function current(): string {
        return $this->protected_get($this->_position);
      }

      public function get_type(): string {
        return 'string';
      }
    };

    $this->assertInstanceOf(TypedList::class, $list);
    $this->assertSame('legacy', $list[0]);
  }
}
