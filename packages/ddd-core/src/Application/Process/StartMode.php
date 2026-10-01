<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

/**
 * How ProcessRunner::start() runs a manual start (register 3.8 "sf start",
 * 5.2). Hosts choose; the 0.6 behaviour is InBand.
 *
 * - InBand: insert, then run the first step immediately under the process
 *   lock (0.6, wp default; sf `ddd.process.inband_start: true`).
 * - Deferred: insert the process as `scheduled` and write a Continue intent
 *   in the caller's transaction (joined, not nested); no lock is taken and
 *   no step runs in the request. A worker's drain runs the first step (sf
 *   default, safe on a pooled connection).
 */
enum StartMode: string {
  case InBand = 'inband';
  case Deferred = 'deferred';
}
