<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Exceptions\ApplicationException;

/**
 * An integration event was published from inside a process wake with no
 * command pass open — a saga step announcing a fact directly.
 *
 * Published this way the fact lands in the outbox as an ORPHAN
 * (command_id = null): no raiser edge, no audit row, a blind spot in every
 * trace. Steps sequence commands; the command's handler is where facts are
 * announced — Result(commands: [...]) is the step's only legal effect.
 */
final class FactPublishedInsideProcess extends ApplicationException {

  public function __construct(string $event_class, string $inside_process_id) {
    parent::__construct(sprintf(
      '%s published inside process #%s with no command pass open — a step '
      . 'must not announce facts. Return the effect as Result(commands: '
      . '[...]) and announce from the command\'s handler.',
      $event_class,
      $inside_process_id
    ));
  }
}
