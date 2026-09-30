<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/**
 * A stored process could not be decoded (e.g. its class no longer exists).
 * The store has already set status `failed` and a non-null
 * `quarantine_reason` (R5: no new status value); the worker continues.
 */
final class QuarantinedProcess extends \RuntimeException {}
