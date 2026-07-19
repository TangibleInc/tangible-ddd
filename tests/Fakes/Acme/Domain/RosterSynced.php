<?php

namespace TangibleDDD\Tests\Fakes\Acme\Domain;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;

/**
 * Twin-style SOURCE: a plain domain event (fat moment) whose to_integration()
 * mints a separate record — the cred LicenseCreated/LicenseCreatedTwin shape.
 * Deliberately NOT stamped: in twin style, #[Touches] belongs on the
 * announced record (the twin).
 */
class RosterSynced extends DomainEvent implements IAnnouncesIntegration {

  public function __construct(
    public readonly Roster $roster,   // fat: carries the aggregate itself
    public readonly int $added,
  ) {}

  public static function action(): string { return 'acme_roster_synced'; }

  public function payload(): array { return ['roster_id' => $this->roster->get_id(), 'added' => $this->added]; }

  public function to_integration(): RosterSyncedTwin {
    return new RosterSyncedTwin((int) $this->roster->get_id(), $this->added);
  }
}
