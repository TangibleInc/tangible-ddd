<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/** The transport did not accept a submission (includes a `0` / missing reference). Retried per the relay budget, then DLQ. */
final class TransportRejected extends \RuntimeException {}
