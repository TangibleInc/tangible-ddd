<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * A behaviour workflow that a fact ignites (D10). Declare the facts with
 * #[StartsOn(SomeFact::class)] (TangibleDDD\Application\Process\StartsOn,
 * repeatable) on the class, and register it with WorkflowIgniter::register().
 * A WorkflowHandler subclass gets every method but workflow_from_fact() from
 * the StartsFromFacts trait.
 *
 * Ignition (WorkflowIgniter::ignite), per delivered fact:
 *   1. workflow_from_fact(): the new workflow, or null to decline;
 *   2. ignition_key(): the dedup key ('' = cannot dedup);
 *   3. in one transaction: ledger claim of the key, save_ignited(), attach;
 *      a lost claim stops here (exactly one workflow per key);
 *   4. after commit: the start marker claim, then start_ignited(); a lost
 *      claim of an attached key whose start never completed restarts the
 *      workflow instead (WorkflowIgniter, outcome Restarted).
 */
interface IStartsFromFact {

  /** Ledger kind: names the workflow (default: the handler class). */
  public function workflow_kind(): string;

  public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow;

  /**
   * The ledger dedup key: WorkflowIgnitionKey::forFact($eventId, kind) for
   * "once per fact", WorkflowIgnitionKey::perMinute() for "once per cron
   * minute", or any stable string. '' disables the dedup for this fact.
   */
  public function ignition_key(IIntegrationEvent $fact, string $eventId): string;

  /** Persist the new workflow (sets its id). Runs inside the ignition transaction. */
  public function save_ignited(BehaviourWorkflow $workflow): void;

  /**
   * Run (or durably hand off) the committed workflow. Runs after the
   * ignition committed, outside its transaction. An exception is logged and
   * reported in WorkflowIgnitionResult::$startError; the ignition stays,
   * and the registered subscriber rethrows it, so the delivery ledger
   * retries the fact. The retry (or any later fact with the same key) finds
   * the workflow ignited but not started, loads it (ILoadsIgnitedWorkflow)
   * and calls start_ignited() again on it while it is active. So this
   * method must tolerate a re-run of a partly run workflow (handle_workflow
   * does: work items are ledgered). A host that needs the start to be
   * atomic with the ignition enqueues it from save_ignited() instead.
   */
  public function start_ignited(BehaviourWorkflow $workflow): void;
}
