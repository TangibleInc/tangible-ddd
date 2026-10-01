<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

use TangibleDDD\Infra\Exceptions\LockingException;

/**
 * The runner could not take a process (or ignition) lock: the port threw
 * LockNotAcquired (timeout, contention, NULL/false backend result, query
 * error; bug 1). Nothing ran and nothing was saved.
 *
 * It extends the 0.6 LockingException, which is what 0.6 callers (Action
 * Scheduler callbacks, consumer code) catch, and carries the port's
 * LockNotAcquired as previous. Retryable. Before throwing it from a direct
 * wake entry (start, ignition's first step, continue_scheduled,
 * handle_timeout) the runner re-queues the wake as a ResumeRetry intent
 * (wave 3); inside ProcessRunner::wake() the caller re-queues its claimed
 * intent; a fact resume is re-delivered by the delivery invoker.
 */
final class ProcessLockUnavailable extends LockingException {}
