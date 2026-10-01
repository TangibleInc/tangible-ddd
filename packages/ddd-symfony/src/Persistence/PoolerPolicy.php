<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

/**
 * What a component that needs a session (advisory locks, LISTEN, the
 * in-band first step) does when its DBAL connection looks pooled (5.2):
 * Warn logs once and carries on; Refuse throws at construction / boot.
 */
enum PoolerPolicy: string {
  case Warn = 'warn';
  case Refuse = 'refuse';
}
