<?php

namespace TangibleDDD\Domain\Exceptions;

/**
 * The code is wired or used wrong: a programming error, never a runtime
 * condition. Nested dispatch, a raise past the seal, a missing handler, an
 * unregistered await are all this.
 *
 * An \Error, not an \Exception, so a consumer's catch (\Exception) — the
 * usual WordPress idiom around a hook callback — cannot swallow it into a
 * log line and carry on (ADR 0018). Domain rules that fail stay
 * InvariantException; runtime and infrastructure failures stay \Exception.
 * Throw this for a misuse instead of \LogicException.
 *
 * It lives in Domain\Exceptions so every layer, the domain included
 * (DDD-L1), may throw it.
 */
class IncorrectUsage extends \Error {
}
