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
 * LockNotAcquired as previous. Retryable; on WordPress the failed Action
 * Scheduler action is visible and can be retried (wave 3 re-queues it as a
 * ResumeRetry intent instead).
 */
final class ProcessLockUnavailable extends LockingException {}
