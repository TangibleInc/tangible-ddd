<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;
use TangibleDDD\Domain\Exceptions\TypeMismatchException;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\Shared\AggregateRoot;
use TangibleDDD\Domain\Shared\IAggregateRoot;
use TangibleDDD\Domain\Shared\IRecordsDomainEvents;
use TangibleDDD\Infra\Persistence\Shared\AggregateRootRepository;
use TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository;

final class TeamCreated extends DomainEvent {
  public function __construct(public readonly string $team_id) {}
  public function payload(): array { return ['team_id' => $this->team_id]; }
}

/** A uuid-identified root with its identity in its own named field (TXP Team). */
final class Team extends AggregateRoot {
  public function __construct(public readonly string $team_id) {
    $this->event(new TeamCreated($team_id));
  }
}

/** A 0.6 int-id aggregate. */
final class LegacyLicense extends Aggregate {
  public static function create(): self {
    $l = new self(null);
    $l->event(new TeamCreated('n/a'));
    return $l;
  }
}

/**
 * TXP demand L2: an identity-agnostic aggregate root and a persist-then-
 * harvest repository that accepts any IRecordsDomainEvents; the int-id
 * Entity/Aggregate pair stays for 0.6 consumers (R2/R3).
 */
final class AggregateRootTest extends TestCase {

  public function test_the_identity_agnostic_root_records_events_and_has_a_canonical_name_without_an_id(): void {
    $team = new Team('0b6f9c1e-0000-4000-8000-000000000001');

    self::assertInstanceOf(IAggregateRoot::class, $team);
    self::assertInstanceOf(IRecordsDomainEvents::class, $team);
    self::assertSame('team', Team::canonical_name());
    self::assertFalse(property_exists($team, 'id'), 'no inherited int id property');
  }

  public function test_the_int_id_aggregate_is_unchanged_and_is_also_an_aggregate_root(): void {
    $l = LegacyLicense::create();

    self::assertInstanceOf(IAggregateRoot::class, $l);
    self::assertNull($l->get_id());
    $l->set_id(7);
    self::assertSame(7, $l->get_id());
    self::assertSame('legacy_license', LegacyLicense::canonical_name());
  }

  public function test_aggregate_root_repository_persists_then_harvests_a_uuid_root(): void {
    $uow = new EventsUnitOfWork();
    $repo = new class ($uow) extends AggregateRootRepository {
      /** @var list<IRecordsDomainEvents> */
      public array $persisted = [];
      protected function get_aggregate_class(): string { return Team::class; }
      protected function persist(IRecordsDomainEvents $aggregate): void { $this->persisted[] = $aggregate; }
    };

    $team = new Team('t-1');
    $repo->save($team);

    self::assertSame([$team], $repo->persisted);
    self::assertCount(1, $uow->drain());
    self::assertSame([], $team->pull_events(), 'harvested');
  }

  public function test_aggregate_root_repository_rejects_another_class(): void {
    $repo = new class (new EventsUnitOfWork()) extends AggregateRootRepository {
      protected function get_aggregate_class(): string { return Team::class; }
      protected function persist(IRecordsDomainEvents $aggregate): void {}
    };

    $this->expectException(TypeMismatchException::class);
    $repo->save(LegacyLicense::create());
  }

  public function test_persists_aggregates_repository_save_accepts_records_domain_events(): void {
    $param = (new \ReflectionMethod(PersistsAggregatesRepository::class, 'save'))->getParameters()[0];
    self::assertSame(IRecordsDomainEvents::class, (string) $param->getType());

    // A 0.6 subclass with persist(Aggregate) keeps working.
    $uow = new EventsUnitOfWork();
    $repo = new class ($uow) extends PersistsAggregatesRepository {
      public int $persisted = 0;
      protected function get_aggregate_class(): string { return LegacyLicense::class; }
      protected function persist(Aggregate $aggregate): void { $this->persisted++; }
      public function get_by_id(int $id): ?Aggregate { return null; }
    };
    $repo->save(LegacyLicense::create());
    self::assertSame(1, $repo->persisted);
    self::assertCount(1, $uow->drain());
  }

  public function test_persists_aggregates_repository_with_a_widened_persist_saves_a_uuid_root(): void {
    $uow = new EventsUnitOfWork();
    $repo = new class ($uow) extends PersistsAggregatesRepository {
      public int $persisted = 0;
      protected function get_aggregate_class(): string { return Team::class; }
      protected function persist(IRecordsDomainEvents $aggregate): void { $this->persisted++; }
      public function get_by_id(int $id): ?Aggregate { return null; }
    };
    $repo->save(new Team('t-2'));
    self::assertSame(1, $repo->persisted);
    self::assertCount(1, $uow->drain());
  }

  public function test_touches_accepts_an_identity_agnostic_root(): void {
    $t = new Touches(Op::Created, Team::class, id: 'team_id');
    self::assertSame(Team::class, $t->aggregate);
  }
}
