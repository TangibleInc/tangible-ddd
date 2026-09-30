<?php

namespace TangibleDDD\Domain\Events;

/**
 * The derived-only record base — twins extend this.
 *
 * NOT a DomainEvent: not raisable (record() rejects it at type level), owns no
 * domain hook. Exists only as the product of IAnnouncesIntegration::to_integration().
 * Consumer plugins re-parent their generated IntegrationEvent base here (it keeps
 * only prefix()).
 */
abstract class IntegrationEvent extends Event implements IIntegrationEvent {
  use IntegrationBehaviour;
}
