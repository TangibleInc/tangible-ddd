<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\WordPress\Retries;

/** V8ChargeListener opted into two retries (three attempts) with #[Retries]. */
#[Retries(2)]
final class V8PatientChargeListener extends V8ChargeListener {}
