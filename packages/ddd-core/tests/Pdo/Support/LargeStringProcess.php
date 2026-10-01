<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Support;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Runtime\Codec\LargeString;

/** D6: a process whose business data carries a LargeString (codec.large-payload, decode.unknown-class). */
final class LargeStringProcess extends LongProcess {

  public function __construct(
    public readonly LargeString $blob = new LargeString(''),
    public readonly ?LargeString $optional = null,
    public readonly string $label = '',
  ) {
    parent::__construct(null);
  }

  protected function begin(): Result {
    return new Result();
  }
}
