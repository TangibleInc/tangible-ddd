<?php

namespace TangibleDDD\Application\Process;

use Psr\Container\ContainerInterface;

/**
 * Self-dispatch hatch for processes — the mirror of CommandBusAware::send().
 *
 * A consumer defines a base class supplying its container (exactly like its
 * Command base does), and edge code gets the symmetric one-liner:
 *
 *   abstract class Process extends LongProcess {
 *     use StartsItself;
 *     protected static function container(): ContainerInterface { return di(); }
 *   }
 *
 *   (new DeadlineBoundCompletionProcess(...))->start();   // edge cold-start
 *
 * Delegates to ProcessRunner::start(), so the whole doctrine rides along:
 * legal only from flat contexts (inside a command pass it throws — record an
 * event and use #[StartsOn] instead), first step runs in-band, correlation
 * minted if absent, source channel stamped.
 */
trait StartsItself {

  abstract protected static function container(): ContainerInterface;

  public function start(): void {
    static::container()->get(ProcessRunner::class)->start($this);
  }
}
