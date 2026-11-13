<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\IncorrectUsage;

/**
 * The intents a captured call produced. Deciding a subset (via of())
 * decides the very same CapturedIntent objects.
 */
final class CapturedIntents implements \Countable, \IteratorAggregate {

  /** @param list<CapturedIntent> $intents */
  public function __construct(private array $intents) {
    $this->intents = array_values($intents);
  }

  /** The intents whose command is an instance of $class; same objects. */
  public function of(string $class): self {
    return new self(array_filter(
      $this->intents,
      fn(CapturedIntent $intent) => $intent->command instanceof $class
    ));
  }

  /** The intents not yet decided; same objects. */
  public function undecided(): self {
    return $this->where(fn(CapturedIntent $intent) => !$intent->is_decided());
  }

  /**
   * The intents for which $predicate(CapturedIntent $intent) returns true;
   * same objects.
   *
   * @param callable(CapturedIntent): bool $predicate
   */
  public function where(callable $predicate): self {
    return new self(array_filter($this->intents, $predicate));
  }

  /**
   * The intents whose command has every given public property strictly
   * (===) equal to the given value; same objects. A command without one of
   * the properties is excluded, not an error.
   *
   * @param array<string, mixed> $properties
   */
  public function matching(array $properties): self {
    return $this->where(function(CapturedIntent $intent) use ($properties) {
      $public = get_object_vars($intent->command);
      foreach( $properties as $name => $value ) {
        if( !array_key_exists($name, $public) || $public[$name] !== $value ) {
          return false;
        }
      }
      return true;
    });
  }

  /**
   * Mark every intent fulfilled and return their commands, so the act can
   * read what was asked and carry the work out in-band: call the domain
   * service the command's handler would call. Do not send them; a send inside
   * the act, outside a window, throws CommandDispatchedInsideCommand.
   *
   * Decides all or none: if any intent is already decided, throws
   * IntentAlreadyDecided and decides none.
   *
   * @return list<ICommand>
   */
  public function fulfil(): array {
    $this->assert_all_undecided();
    $commands = [];
    foreach( $this->intents as $intent ) {
      $intent->decide(Decision::fulfilled);
      $commands[] = $intent->command;
    }
    return $commands;
  }

  /**
   * Mark every intent rejected with $reason: the act decides against them.
   * An empty (or blank) reason is IncorrectUsage. Decides all or none: if any
   * intent is already decided, throws IntentAlreadyDecided and decides none.
   */
  public function reject(string $reason): void {
    $reason = trim($reason);
    if( $reason === '' ) {
      throw new IncorrectUsage('A rejected intent needs a reason.');
    }
    $this->assert_all_undecided();
    foreach( $this->intents as $intent ) {
      $intent->decide(Decision::rejected, $reason);
    }
  }

  /** @return list<CapturedIntent> */
  public function all(): array {
    return $this->intents;
  }

  public function count(): int {
    return count($this->intents);
  }

  public function getIterator(): \ArrayIterator {
    return new \ArrayIterator($this->intents);
  }

  /** Decide all or none: a half-decided subset would be unrecoverable. */
  private function assert_all_undecided(): void {
    foreach( $this->intents as $intent ) {
      if( $intent->is_decided() ) {
        throw new IntentAlreadyDecided($intent);
      }
    }
  }
}
