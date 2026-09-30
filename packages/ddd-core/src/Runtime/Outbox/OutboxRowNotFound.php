<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/** An administration call named an outbox or DLQ row that does not exist. */
final class OutboxRowNotFound extends \RuntimeException {}
