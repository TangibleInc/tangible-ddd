<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection;

/** Container tags the bundle reads at compile time. */
final class DddTags {

  /** ICommandHandler services (autoconfigured), located by class for Tactician's naming convention. */
  public const COMMAND_HANDLER = 'tangible_ddd.command_handler';

  /** IQueryHandler services (autoconfigured). */
  public const QUERY_HANDLER = 'tangible_ddd.query_handler';

  /**
   * SelfHandlingCommand / SelfHandlingQuery classes (autoconfigured when the
   * app's resource loading registers them). They are messages, not services:
   * the tag only tells HandlerLocatorPass which handle() signatures to read.
   */
  public const SELF_HANDLING = 'tangible_ddd.self_handling';

  /**
   * Integration listeners (#[AsIntegrationListener], or IntegrationTranslator
   * subclasses once core ships it). Optional attribute `event`: the fact class
   * or marker; otherwise read from the listener's event_class().
   */
  public const INTEGRATION_LISTENER = 'tangible_ddd.integration_listener';

  /** In-transaction domain-event reactions: attributes `event`, `priority`, `method`. */
  public const DOMAIN_LISTENER = 'tangible_ddd.domain_listener';

  /**
   * D10: behaviour workflow services implementing IStartsFromFact
   * (autoconfigured). Each #[StartsOn] fact on the class becomes a core
   * WorkflowIgniter subscriber in the compiled subscription map; the service
   * is built at the first delivery of a matching fact.
   */
  public const WORKFLOW = 'tangible_ddd.workflow';

  /**
   * W1: WorkflowHandler services that continue through the wakeup scheduler
   * (IContinuesWorkflows, autoconfigured; the ReschedulesThroughWakeups
   * trait). Located by class for the workflow wake target.
   */
  public const CONTINUES_WORKFLOW = 'tangible_ddd.continues_workflow';

  /**
   * W2: BaseBehaviourConfig subclasses found by the app's resource loading
   * (autoconfigured). Values, not services: BehaviourTypePass reads each
   * class's get_behaviour_type() and the bundle registers the types at boot.
   */
  public const BEHAVIOUR_CONFIG = 'tangible_ddd.behaviour_config';

  /** LongProcess classes (the existing core tag read by LongProcessCatalogPass). */
  public const LONG_PROCESS = 'ddd.long_process';
}
