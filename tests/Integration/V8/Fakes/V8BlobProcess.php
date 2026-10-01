<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Runtime\Codec\LargeString;

/** A one-step process whose state carries a binary LargeString (D6). */
class V8BlobProcess extends LongProcess {

  public function __construct(
    public readonly ?LargeString $blob = null,
    public readonly string $label = '',
  ) {
    parent::__construct(null);
  }

  protected function react(): Result {
    return new Result();
  }
}
