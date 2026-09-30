<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use League\Tactician\CommandBus;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;

/**
 * What a host (mem, pdo, wp, sf) provides to the shared scenarios
 * (register section 4). One fixture instance serves exactly one test.
 *
 * Two kinds of members:
 *
 * - PORTS: the host's real implementation of each core port, all bound to
 *   the ONE host connection (boundary, outbox, ledger, ...). Scenarios call
 *   them directly where a scenario is about a port contract (lease fencing,
 *   pauses, replay identity).
 * - HOST OPERATIONS: the host's own pipeline (command bus, relay step,
 *   delivery runner, worker loop) and the few fault-injection and read-back
 *   seams a scenario needs. Each host maps these onto its real machinery:
 *   wp onto Action Scheduler and the WordPress bus, sf onto Messenger, pdo
 *   onto DurableRuntime and separate `php` processes where the register
 *   marks it.
 *
 * Lifecycle and isolation: setUp() gives the test a FRESH schema (SQL hosts
 * create a per-test database or schema named from
 * ScenarioContext::uniqueName(), apply the host schema, and bind every port
 * to it; nothing is wrapped in a per-test transaction, because scenarios
 * test transactions). tearDown() drops it and clears every process-static
 * registration the fixture made (RuntimeReset, HostDefaults, Correlation).
 * A fixture must not rely on HostDefaults being populated: scenarios such
 * as cmd.no-boundary need a bus with no boundary at all.
 */
interface HostFixture {

  /** 'mem' | 'pdo' | 'wp' | 'sf' */
  public function hostName(): string;

  /** Create and bind a fresh schema for this one test. */
  public function setUp(ScenarioContext $context): void;

  /** Drop the schema and clear process-static state (never throws). */
  public function tearDown(): void;

  // ── time ─────────────────────────────────────────────────────────────────

  /** The IClock every port of this host reads. */
  public function clock(): IClock;

  /** Move the host clock forward (FrozenClock on mem, EnvOffsetClock across processes). */
  public function advanceClock(int $seconds): void;

  // ── ports ────────────────────────────────────────────────────────────────

  public function boundary(): ITransactionBoundary;

  public function outbox(): IOutboxStore;

  public function outboxAdministration(): IOutboxAdministration;

  public function relayPauses(): IRelayPauseStore;

  public function transport(): ITransport;

  public function ledger(): IDeliveryLedger;

  /** Subscribers added here are the ones deliver()/deliverTransported() run. */
  public function subscriptions(): ISubscriptionRegistry;

  /** The re-entrant process lock, guarded by RuntimeReset::guardLock(). */
  public function processLock(): IProcessLock;

  /** The ONE EventsUnitOfWork the bus's publish middleware drains; registered with RuntimeReset. */
  public function events(): EventsUnitOfWork;

  /** A scenario-owned domain table on the host connection (the "domain row"). */
  public function scenarioRows(): ScenarioRows;

  // ── command pipeline ─────────────────────────────────────────────────────

  /**
   * The host command bus in the frozen middleware order: act bracket
   * (Correlation + audit) → Transaction → DomainEventsPublish → handler.
   *
   * @param array<class-string, callable(object): mixed> $handlers command class => handler
   */
  public function commandBus(array $handlers, BusOptions $options = new BusOptions()): CommandBus;

  /** Register a synchronous in-transaction reaction on the bus's domain-event dispatcher. */
  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10): void;

  /** Make the NEXT commit of the host boundary fail (driver-level COMMIT failure). */
  public function failNextCommit(string $reason): void;

  /** @return list<AuditEntry> audit rows closed so far, oldest first */
  public function auditTrail(): array;

  // ── relay ────────────────────────────────────────────────────────────────

  /**
   * One relay step of the host (claim due rows, submit to the transport,
   * accept / retryLater / deadLetter per the 5.1 relay budget).
   *
   * @throws SimulatedCrash when crashNextRelayAfterSubmit() was armed
   */
  public function relayOnce(int $limit = 50): RelayReport;

  /** The next ITransport::submit() throws $e (TransportRejected when null). */
  public function rejectNextSubmission(?\Throwable $e = null): void;

  /** The next submission is taken by the transport but yields no reference. */
  public function acceptNextSubmissionWithoutRef(): void;

  /**
   * The next relay step dies after the transport took the submission and
   * before IOutboxStore::accept (pdo: the relay `php` process exits).
   * The claim's lease stays in place, as after a real crash.
   */
  public function crashNextRelayAfterSubmit(): void;

  /**
   * What the transport holds, in submission order, duplicates kept. A
   * submission that produced no reference is not held.
   *
   * @return list<TransportedFact>
   */
  public function transported(): array;

  /**
   * Seed a row as a 0.6 writer would have left it: `delay_seconds` > 0 and
   * an absolute `scheduled_at`. Hosts without a legacy schema append the
   * equivalent port record (due_at = $scheduledAt).
   *
   * @return string the event id
   */
  public function seedLegacyDelayedFact(IIntegrationEvent $fact, int $delaySeconds, \DateTimeImmutable $scheduledAt): string;

  // ── delivery ─────────────────────────────────────────────────────────────

  /** Run the host delivery runner once for one wrapped fact. */
  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome;

  /**
   * Deliver every transport message not yet delivered by this method, in
   * submission order, as the host's delivery runner would.
   *
   * @return list<DeliveryOutcome>
   */
  public function deliverTransported(string $eventClass): array;

  // ── worker ───────────────────────────────────────────────────────────────

  /**
   * Run $messages in ONE worker, with the host's message-boundary reset
   * (RuntimeReset::betweenMessages or the host's equivalent) after each.
   *
   * @param list<callable(): void> $messages
   */
  public function runWorker(array $messages): WorkerRun;

  /**
   * The process runner's per-message transients, e.g. ['resume_argument' => …],
   * or null when this host has no process runner yet (mem until wave 3).
   *
   * @return array<string, mixed>|null
   */
  public function runnerTransients(): ?array;
}
