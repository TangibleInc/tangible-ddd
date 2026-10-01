<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** How HostFixture::command_bus() composes the host bus for one scenario. */
final class BusOptions {

  /**
   * @param bool $boundary false builds the bus with NO ITransactionBoundary
   *                           anywhere (constructor and HostDefaults), for cmd.no-boundary
   * @param bool $audit        false turns command audit off (the policy skips the write);
   *                           the guards must hold either way
   */
  public function __construct(
    public readonly bool $boundary = true,
    public readonly bool $audit = true,
  ) {}
}
