<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Exceptions\BusinessConstraintException;
use TangibleDDD\Domain\Exceptions\ConflictException;
use TangibleDDD\Domain\Exceptions\TypeMismatchException;
use TangibleDDD\Domain\Shared\AggregateRoot;
use TangibleDDD\Domain\Shared\IRecordsDomainEvents;
use TangibleDDD\Infra\Persistence\Shared\AggregateRootRepository;

final class InviteRevoked extends DomainEvent {
  public function __construct(public readonly string $invite_id) {}
  public function payload(): array { return ['invite_id' => $this->invite_id]; }
}

/** A root that records a fact as it goes away (TXP TeamInvite). */
final class Invite extends AggregateRoot {
  public function __construct(public readonly string $invite_id) {}

  public function revoke(): void {
    $this->event(new InviteRevoked($this->invite_id));
  }
}

/** A root of another class, for the class check. */
final class Stranger extends AggregateRoot {
}

/**
 * Wave 5, TXP wiring demands L9 and L10: AggregateRootRepository::remove()
 * deletes then harvests, like save(); a core 409 family exists.
 */
final class RemoveAndConflictTest extends TestCase {

  public function test_remove_deletes_then_harvests_the_recorded_events(): void {
    $uow = new EventsUnitOfWork();
    $repo = new class ($uow) extends AggregateRootRepository {
      /** @var list<string> */
      public array $log = [];
      public ?EventsUnitOfWork $seen = null;
      protected function aggregate_class(): string { return Invite::class; }
      protected function persist(IRecordsDomainEvents $aggregate): void { $this->log[] = 'persist'; }
      protected function delete(IRecordsDomainEvents $aggregate): void {
        $this->log[] = 'delete:' . count($this->events->drain());
      }
    };

    $invite = new Invite('i-1');
    $invite->revoke();
    $repo->remove($invite);

    self::assertSame(['delete:0'], $repo->log, 'delete ran before the harvest');
    $drained = $uow->drain();
    self::assertCount(1, $drained);
    self::assertInstanceOf(InviteRevoked::class, $drained[0]);
    self::assertSame([], $invite->pull_events(), 'harvested');
  }

  public function test_remove_rejects_another_class_before_deleting(): void {
    $repo = new class (new EventsUnitOfWork()) extends AggregateRootRepository {
      public bool $deleted = false;
      protected function aggregate_class(): string { return Invite::class; }
      protected function persist(IRecordsDomainEvents $aggregate): void {}
      protected function delete(IRecordsDomainEvents $aggregate): void { $this->deleted = true; }
    };

    try {
      $repo->remove(new Stranger());
      self::fail('expected the class check');
    } catch (TypeMismatchException) {
    }
    self::assertFalse($repo->deleted);
  }

  public function test_a_repository_without_delete_refuses_remove_and_harvests_nothing(): void {
    $uow = new EventsUnitOfWork();
    $repo = new class ($uow) extends AggregateRootRepository {
      protected function aggregate_class(): string { return Invite::class; }
      protected function persist(IRecordsDomainEvents $aggregate): void {}
    };
    $invite = new Invite('i-2');
    $invite->revoke();

    try {
      $repo->remove($invite);
      self::fail('expected the missing delete() to be refused');
    } catch (\LogicException $e) {
      self::assertStringContainsString('delete()', $e->getMessage());
    }
    self::assertSame([], $uow->drain(), 'nothing harvested for a removal that did not happen');
    self::assertCount(1, $invite->pull_events(), 'the events stay on the aggregate');
  }

  public function test_the_core_conflict_is_a_business_constraint(): void {
    $conflict = new ConflictException('the invite was accepted meanwhile');

    self::assertInstanceOf(BusinessConstraintException::class, $conflict, 'an edge mapping the family answers 409');
    self::assertSame('the invite was accepted meanwhile', $conflict->getMessage());
    self::assertFalse((new \ReflectionClass(ConflictException::class))->isFinal(), 'hosts re-parent their conflicts to it');
  }
}
