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
   * Integration listeners (#[AsIntegrationListener], or IntegrationTranslator
   * subclasses once core ships it). Optional attribute `event`: the fact class
   * or marker; otherwise read from the listener's event_class().
   */
  public const INTEGRATION_LISTENER = 'tangible_ddd.integration_listener';

  /** In-transaction domain-event reactions: attributes `event`, `priority`, `method`. */
  public const DOMAIN_LISTENER = 'tangible_ddd.domain_listener';

  /** LongProcess classes (the existing core tag read by LongProcessCatalogPass). */
  public const LONG_PROCESS = 'ddd.long_process';
}
