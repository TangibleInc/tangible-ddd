<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Runtime\Ops\IOperatorView;

/**
 * Optional seam for `effect.performed-not-recorded` (E2; wave 5, CR-W5C5-2;
 * core CR-W5CC-6). A separate interface over EffectHost, so a wave-4
 * EffectHost fixture keeps compiling; a fixture without it has the scenario
 * skipped with the request id.
 *
 * - effect_journal() (EffectHost) returns an ITracksEffectState journal on
 *   the host connection, timestamped by HostFixture::clock();
 * - operator_view(): the host's merged operator view (the same one as
 *   ProcessHost::operator_view() when the fixture implements both), with
 *   the core UnrecordedEffects source over that journal at its default
 *   threshold (UnrecordedEffects::DEFAULT_AFTER_SECONDS) and the host clock:
 *   the `effect` layer lists an entry performed more than 300 s ago and not
 *   recorded.
 */
interface EffectStateHost extends EffectHost {

  public function operator_view(): IOperatorView;
}
