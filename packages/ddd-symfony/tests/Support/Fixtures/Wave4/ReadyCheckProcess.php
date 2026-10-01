<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Application\Process\IPrecheckAwait;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\PrecheckSatisfied;
use TangibleDDD\Application\Process\Result;

/** D3 register-then-check: awaits a keyed JobDone, and prechecks a published readiness flag. */
final class ReadyCheckProcess extends LongProcess implements IPrecheckAwait {

  public static bool $ready = false;

  public function __construct(public readonly int $app_id = 1) {
    parent::__construct(null);
  }

  protected function await_ready(): Result {
    Trail::note('await_ready');
    return new Result(await: AwaitEvent::keyed(JobDone::class, $this->step_ref('ready'), timeout_seconds: 600));
  }

  protected function provision(mixed $payload, mixed $arrival): Result {
    Trail::note('provision:' . (is_string($arrival) ? $arrival : get_debug_type($arrival)));
    return new Result();
  }

  public function already_satisfied(IAwaitMechanism $await): ?PrecheckSatisfied {
    return self::$ready ? PrecheckSatisfied::with('precheck') : null;
  }
}
