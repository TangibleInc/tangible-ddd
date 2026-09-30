<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/** An outbox append failed (duplicate event_id, driver error); the command must roll back. */
final class OutboxWriteFailed extends \RuntimeException {}
