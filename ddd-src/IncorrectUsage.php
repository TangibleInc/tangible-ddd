<?php

namespace TangibleDDD;

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
 * It belongs to no layer: misuse can happen in any of them, so it sits at
 * the framework root and DDD-L1 names it as a dependency the domain may take.
 */
class IncorrectUsage extends \Error {
}
