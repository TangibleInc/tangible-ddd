<?php

namespace TangibleDDD\Domain\Events;

/**
 * A raisable event announcing that a record will exist. Implementors: fat
 * moments (return their derived twin — hand-written fact selection + naming,
 * the irreducible five lines) and scalar self-publishers (IntegrationBehaviour's
 * default returns $this). Narrow your return type — it IS the twin announcement.
 */
interface IAnnouncesIntegration {
  public function to_integration(): IIntegrationEvent;
}
