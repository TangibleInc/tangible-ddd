<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/** An insert/update failed (driver error, unknown id, re-insert). Never answered with set_id(0) (C27). */
final class ProcessStoreFailed extends \RuntimeException {}
