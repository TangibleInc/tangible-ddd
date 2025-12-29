<?php

namespace TangibleDDD\Infra\Shared;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * A <generic> list base class for strongly-typed lists.
 *
 * Ported from tangible-cred (originally adapted from):
 * https://bitfire.co/en/programming/strongly-typed-arrays-in-php
 *
 * Extend this abstract class and implement:
 * - offsetGet(): YourReturnType
 * - current(): YourReturnType
 * - get_type(): string (class name or scalar 'string'/'int'/'mixed')
 */
abstract class TypedList implements \ArrayAccess, \Iterator, \Countable, \SeekableIterator, \JsonSerializable {

  protected string $_type = 'mixed';
  protected ?int $_position = 0;
  protected array $_list;

  private bool $_is_associated = false;
  private array $_keys;

  public function __construct(array $list = []) {
    $this->_type = $this->get_type();

    foreach ($list as $value) {
      if (!$this->is_of_valid_type($value)) {
        $msg = get_class($this)
          . ' only accepts objects of type "'
          . $this->_type
          . '", "'
          . gettype($value)
          . '" passed';
        throw new InvalidArgumentException($msg, 1);
      }
    }

    $this->_list = $list;
  }

  public function keys(): array {
    return array_keys($this->_list);
  }

  /**
   * Example: echo $list[$index]
   * @param mixed $index index may be numeric or hash key
   */
  #[\ReturnTypeWillChange]
  public abstract function offsetGet($index);

  /**
   * Example: foreach ($list as $key => $value)
   */
  #[\ReturnTypeWillChange]
  public abstract function current();

  /**
   * @return string the name of the type list supports or mixed
   */
  public abstract function get_type(): string;

  /**
   * Return a new instance of the subclass with the given list.
   */
  public static function of(array $list): static {
    return new static($list);
  }

  /**
   * Clone the current list into a new object.
   */
  #[\ReturnTypeWillChange]
  public function clone(): static {
    $new = new static();
    $new->_list = array_merge([], $this->_list);
    return $new;
  }

  public function count(): int {
    return count($this->_list);
  }

  /**
   * SeekableIterator implementation.
   * @throws OutOfBoundsException
   */
  public function seek($position): void {
    if (!isset($this->_list[$position])) {
      throw new OutOfBoundsException("invalid seek position ($position)");
    }

    $this->_position = $position;
  }

  public function rewind(): void {
    if ($this->_is_associated) {
      $this->_keys = array_keys($this->_list);
      $this->_position = array_shift($this->_keys);
    } else {
      $this->_position = 0;
    }
  }

  public function key(): mixed {
    return $this->_position;
  }

  public function next(): void {
    if ($this->_is_associated) {
      $this->_position = array_shift($this->_keys);
    } else {
      ++$this->_position;
    }
  }

  public function valid(): bool {
    return isset($this->_list[$this->_position]);
  }

  protected function is_of_valid_type(mixed $value): bool {
    return match ($this->_type) {
      'mixed' => true,
      'string' => is_string($value),
      'int' => is_int($value),
      default => $value instanceof $this->_type
    };
  }

  public function offsetSet($index, $value): void {
    if (!$this->is_of_valid_type($value)) {
      $msg = get_class($this)
        . ' only accepts objects of type "'
        . $this->_type
        . '", "'
        . gettype($value)
        . '" passed';
      throw new InvalidArgumentException($msg, 1);
    }

    if (empty($index)) {
      $this->_list[] = $value;
    } else {
      $this->_is_associated = true;
      $this->_list[$index] = $value;
    }
  }

  public function offsetUnset($index): void {
    unset($this->_list[$index]);
  }

  public function offsetExists($index): bool {
    return isset($this->_list[$index]);
  }

  public function &raw(): array {
    return $this->_list;
  }

  public function ksort(int $flags = SORT_REGULAR): static {
    ksort($this->_list, $flags);
    return $this;
  }

  public function empty(): bool {
    return empty($this->_list);
  }

  /**
   * Helper used by offsetGet() and current().
   */
  protected function protected_get($key) {
    if ($this->_is_associated) {
      if (isset($this->_list[$key])) {
        return $this->_list[$key];
      }
    }

    return $this->_list[$key];
  }

  public function filter(callable $fn, bool $clone = false): static {
    if ($clone) {
      return new static(array_filter(
        array_merge([], $this->_list),
        $fn
      ));
    }

    $this->_list = array_filter($this->_list, $fn);
    return $this;
  }

  #[\ReturnTypeWillChange]
  public function last() {
    if ($this->empty()) {
      return null;
    }

    $keys = array_keys($this->_list);
    $lastKey = end($keys);

    return $this->protected_get($lastKey);
  }

  public function __toString(): string {
    return json_encode(array_slice($this->_list, 0, 5));
  }

  public function jsonSerialize(): array {
    return $this->_list;
  }
}


