<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Lock;

/**
 * A session-dependent component (advisory lock, LISTEN, in-band start) was
 * given a connection that looks pooled while the policy is Refuse (5.2).
 * Configure a direct (non-pooled) endpoint for workers.
 */
final class PooledConnectionRefused extends \LogicException {}
