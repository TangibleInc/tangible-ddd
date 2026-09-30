<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Lock;

/**
 * The lock was not definitely acquired: timeout, contention, a NULL/false
 * backend result, or a query error (bug 1). Retryable: the runner schedules a
 * ResumeRetry intent or re-queues the delivery with backoff; the wake is never
 * dropped. The critical section was never entered.
 */
final class LockNotAcquired extends \RuntimeException {}
