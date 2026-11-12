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

  /**
   * Mark every intent fulfilled and return their commands for the act to send.
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
