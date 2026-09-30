# Wave 2 round 1: move table (split-move)

Machine-readable record of the mechanical split (register 1.1, 1.3, 1.4; report A section 3 with the X4, R2 and split amendments). Branch `wave2/split-move`, based on the wave-1 gate `cf2ee0c`.

Parse rule: every row of the table under **Moves** starts with `| ddd-src/` or `| ddd-wordpress/` and has exactly six cells: `old_path | new_path | fqcn | disposition | owner | note`. `fqcn` is `-` for non-PSR-4 files under `ddd-wordpress/`.

Dispositions:

- `keep`: moved unchanged into ddd-core, same FQCN (131, including the two DI bridge passes per X4). Seven got comment-only rewording so no WordPress symbol name appears under `packages/ddd-core/src`; `assert.php` got a `function_exists` guard (it is now a Composer `files` entry as well as a loader include).
- `split-core`: the split's legacy FQCN is core-owned and was changed in place (`ConsumerHandle`, `IDDDConfig`, `IntegrationConformance`).
- `split-r2`: legacy FQCN owned by ddd-wp as a thin subclass of a new or wave-1 core class (`TransactionMiddleware` over `TransactionalCommandMiddleware`, `IntegrationListener` over `IntegrationTranslator`).
- `split-deferred`: a split whose core half cannot be separated mechanically (it calls WordPress functions). The whole file sits in `packages/ddd-wp/src` this round, same FQCN; round 2 moves its core form onto the wave-1 ports and back into ddd-core.
- `move`: one of the 18 whole-file moves to ddd-wp.
- `wordpress`: `ddd-wordpress/*` moved to `packages/ddd-wp/wordpress/`. `forwarding shim left at old path` marks the 14 legacy paths that keep a one-line `require_once` shim (see below).

Counts: 131 keep + 3 split-core + 2 split-r2 + 12 split-deferred + 18 move = 166 `ddd-src` files; 50 `ddd-wordpress` files, 14 shims.

## New files

| path | fqcn | package | why |
|---|---|---|---|
| packages/ddd-core/src/Application/EventHandlers/IntegrationTranslator.php | TangibleDDD\Application\EventHandlers\IntegrationTranslator | core | register 1.4 core form of IntegrationListener |
| packages/ddd-core/src/Infra/Consumers/NotAWordPressConsumer.php | TangibleDDD\Infra\Consumers\NotAWordPressConsumer | core | register 1.4 / 3.1, thrown by ConsumerHandle::config() |
| packages/ddd-wp/wordpress/Adapter/UncheckedWpdbTransactionBoundary.php | TangibleDDD\WordPress\Adapter\UncheckedWpdbTransactionBoundary | wp | 0.6 transaction behaviour for the R2 TransactionMiddleware (CR-SM-1) |
| packages/ddd-wp/wordpress/Testing/WpIntegrationConformance.php | TangibleDDD\WordPress\Testing\WpIntegrationConformance | wp | wp subclass of the IntegrationConformance split (CR-SM-2) |
| (14 shims at legacy ddd-wordpress/ paths) | - | root shim | forwarding shims, see below |

## Legacy paths kept as forwarding shims

`ddd-wordpress/self/index.php` (B5: every legacy loader's self-consume hook requires it). The 13 files the winner's procedural list in `tangible-ddd.php` still names: `db.php`, `tables.php`, `migrations.php`, `hooks.php`, `modules.php`, `audit.php`, `touches.php`, `locking.php`, `secret.php`, `integration-events.php`, `infrastructure-events.php`, `dashboard.php`, `cli/register.php`. That list skips a missing file silently, and `LoaderIdentityTest` (packaging) pins the `'ddd-wordpress/hooks.php'` literal, so the list was not changed; packaging retires these 13 shims when it switches the list with the version-unique loader entry. `LegacyPathShimsTest` guards both.

Each shim was added in a commit after the pure `git mv`, so `git log --follow` on the moved file sees the rename.

## Core files that still load a wp-resident split class

These `keep` files contain no WordPress symbol but extend a `split-deferred` class, so they only load when ddd-wp's `src/` is on the autoloader (true for the root autoload and the ddd-core suite, which boots the root autoloader). They become core-only when round 2 lands the core forms:

| core file | extends (in packages/ddd-wp/src this round) |
|---|---|
| Application/Infrastructure/OutboxAttemptFailed.php | InfrastructureEvent |
| Application/Infrastructure/OutboxDeadLettered.php | InfrastructureEvent |
| Application/Infrastructure/WorkflowFailed.php | InfrastructureEvent |
| Application/Infrastructure/FactDeliveredUnheard.php | InfrastructureEvent |
| Application/Infrastructure/ProcessFailed.php | InfrastructureEvent |
| Application/Commands/ReplayDeadLetterCommand.php | Command |
| Application/Commands/DiscardDeadLetterCommand.php | Command |
| Application/Commands/PurgeOutboxCommand.php | Command |
| Application/Commands/RetryDeliveryCommand.php | Command |

`Runtime/Delivery/SubscriptionRegistrar.php` names `ProcessRunner` in a parameter union type only (no load); CR-3 narrows it to `?IProcessEntry` in round 2. The ddd-conformance bootstrap appends `packages/ddd-wp/src/` for `OutboxConfig` until its core form lands.

## Moves

| old_path | new_path | fqcn | disposition | owner | note |
|---|---|---|---|---|---|
| ddd-src/Application/BehaviourWorkflows/IWorkItem.php | packages/ddd-core/src/Application/BehaviourWorkflows/IWorkItem.php | TangibleDDD\Application\BehaviourWorkflows\IWorkItem | keep | core | - |
| ddd-src/Application/BehaviourWorkflows/WorkflowHandler.php | packages/ddd-wp/src/Application/BehaviourWorkflows/WorkflowHandler.php | TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/CQRS/CommandBusAware.php | packages/ddd-core/src/Application/CQRS/CommandBusAware.php | TangibleDDD\Application\CQRS\CommandBusAware | keep | core | - |
| ddd-src/Application/CQRS/HandlerClassNameInflector.php | packages/ddd-core/src/Application/CQRS/HandlerClassNameInflector.php | TangibleDDD\Application\CQRS\HandlerClassNameInflector | keep | core | - |
| ddd-src/Application/CQRS/QueryBusAware.php | packages/ddd-core/src/Application/CQRS/QueryBusAware.php | TangibleDDD\Application\CQRS\QueryBusAware | keep | core | - |
| ddd-src/Application/CQRS/SelfExecutingCommandMiddleware.php | packages/ddd-core/src/Application/CQRS/SelfExecutingCommandMiddleware.php | TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware | keep | core | - |
| ddd-src/Application/CommandHandlers/DiscardDeadLetterHandler.php | packages/ddd-wp/src/Application/CommandHandlers/DiscardDeadLetterHandler.php | TangibleDDD\Application\CommandHandlers\DiscardDeadLetterHandler | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/CommandHandlers/ICommandHandler.php | packages/ddd-core/src/Application/CommandHandlers/ICommandHandler.php | TangibleDDD\Application\CommandHandlers\ICommandHandler | keep | core | - |
| ddd-src/Application/CommandHandlers/PurgeOutboxHandler.php | packages/ddd-wp/src/Application/CommandHandlers/PurgeOutboxHandler.php | TangibleDDD\Application\CommandHandlers\PurgeOutboxHandler | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/CommandHandlers/ReplayDeadLetterHandler.php | packages/ddd-wp/src/Application/CommandHandlers/ReplayDeadLetterHandler.php | TangibleDDD\Application\CommandHandlers\ReplayDeadLetterHandler | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/CommandHandlers/RetryDeliveryHandler.php | packages/ddd-wp/src/Application/CommandHandlers/RetryDeliveryHandler.php | TangibleDDD\Application\CommandHandlers\RetryDeliveryHandler | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/Commands/Command.php | packages/ddd-wp/src/Application/Commands/Command.php | TangibleDDD\Application\Commands\Command | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/Commands/DiscardDeadLetterCommand.php | packages/ddd-core/src/Application/Commands/DiscardDeadLetterCommand.php | TangibleDDD\Application\Commands\DiscardDeadLetterCommand | keep | core | - |
| ddd-src/Application/Commands/ICommand.php | packages/ddd-core/src/Application/Commands/ICommand.php | TangibleDDD\Application\Commands\ICommand | keep | core | - |
| ddd-src/Application/Commands/ITransactionalCommand.php | packages/ddd-core/src/Application/Commands/ITransactionalCommand.php | TangibleDDD\Application\Commands\ITransactionalCommand | keep | core | - |
| ddd-src/Application/Commands/PurgeOutboxCommand.php | packages/ddd-core/src/Application/Commands/PurgeOutboxCommand.php | TangibleDDD\Application\Commands\PurgeOutboxCommand | keep | core | - |
| ddd-src/Application/Commands/ReplayDeadLetterCommand.php | packages/ddd-core/src/Application/Commands/ReplayDeadLetterCommand.php | TangibleDDD\Application\Commands\ReplayDeadLetterCommand | keep | core | - |
| ddd-src/Application/Commands/RetryDeliveryCommand.php | packages/ddd-core/src/Application/Commands/RetryDeliveryCommand.php | TangibleDDD\Application\Commands\RetryDeliveryCommand | keep | core | - |
| ddd-src/Application/Commands/SelfHandlingCommand.php | packages/ddd-core/src/Application/Commands/SelfHandlingCommand.php | TangibleDDD\Application\Commands\SelfHandlingCommand | keep | core | - |
| ddd-src/Application/Correlation/Cause.php | packages/ddd-core/src/Application/Correlation/Cause.php | TangibleDDD\Application\Correlation\Cause | keep | core | - |
| ddd-src/Application/Correlation/Correlation.php | packages/ddd-core/src/Application/Correlation/Correlation.php | TangibleDDD\Application\Correlation\Correlation | keep | core | - |
| ddd-src/Application/Correlation/CorrelationMiddleware.php | packages/ddd-wp/src/Application/Correlation/CorrelationMiddleware.php | TangibleDDD\Application\Correlation\CorrelationMiddleware | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/Correlation/Kind.php | packages/ddd-core/src/Application/Correlation/Kind.php | TangibleDDD\Application\Correlation\Kind | keep | core | - |
| ddd-src/Application/Correlation/TraceContext.php | packages/ddd-core/src/Application/Correlation/TraceContext.php | TangibleDDD\Application\Correlation\TraceContext | keep | core | - |
| ddd-src/Application/DataTransferObjects/BaseDTO.php | packages/ddd-core/src/Application/DataTransferObjects/BaseDTO.php | TangibleDDD\Application\DataTransferObjects\BaseDTO | keep | core | - |
| ddd-src/Application/DataTransferObjects/Collections/BaseDTOCollection.php | packages/ddd-core/src/Application/DataTransferObjects/Collections/BaseDTOCollection.php | TangibleDDD\Application\DataTransferObjects\Collections\BaseDTOCollection | keep | core | - |
| ddd-src/Application/DataTransferObjects/Collections/IDTOCollection.php | packages/ddd-core/src/Application/DataTransferObjects/Collections/IDTOCollection.php | TangibleDDD\Application\DataTransferObjects\Collections\IDTOCollection | keep | core | - |
| ddd-src/Application/DataTransferObjects/IDataTransferObject.php | packages/ddd-core/src/Application/DataTransferObjects/IDataTransferObject.php | TangibleDDD\Application\DataTransferObjects\IDataTransferObject | keep | core | - |
| ddd-src/Application/EventHandlers/IEventHandler.php | packages/ddd-core/src/Application/EventHandlers/IEventHandler.php | TangibleDDD\Application\EventHandlers\IEventHandler | keep | core | - |
| ddd-src/Application/EventHandlers/IntegrationListener.php | packages/ddd-wp/src/Application/EventHandlers/IntegrationListener.php | TangibleDDD\Application\EventHandlers\IntegrationListener | split-r2 | wp | - |
| ddd-src/Application/EventHandlers/WordPressActionHandler.php | packages/ddd-wp/src/Application/EventHandlers/WordPressActionHandler.php | TangibleDDD\Application\EventHandlers\WordPressActionHandler | move | wp | - |
| ddd-src/Application/Events/DomainEventsPublishMiddleware.php | packages/ddd-core/src/Application/Events/DomainEventsPublishMiddleware.php | TangibleDDD\Application\Events\DomainEventsPublishMiddleware | keep | core | - |
| ddd-src/Application/Events/EventRouter.php | packages/ddd-core/src/Application/Events/EventRouter.php | TangibleDDD\Application\Events\EventRouter | keep | core | - |
| ddd-src/Application/Events/EventsUnitOfWork.php | packages/ddd-core/src/Application/Events/EventsUnitOfWork.php | TangibleDDD\Application\Events\EventsUnitOfWork | keep | core | - |
| ddd-src/Application/Events/Footprint.php | packages/ddd-core/src/Application/Events/Footprint.php | TangibleDDD\Application\Events\Footprint | keep | core | - |
| ddd-src/Application/Events/IDomainEventDispatcher.php | packages/ddd-core/src/Application/Events/IDomainEventDispatcher.php | TangibleDDD\Application\Events\IDomainEventDispatcher | keep | core | - |
| ddd-src/Application/Events/IIntegrationEventBus.php | packages/ddd-core/src/Application/Events/IIntegrationEventBus.php | TangibleDDD\Application\Events\IIntegrationEventBus | keep | core | - |
| ddd-src/Application/Events/IntegrationEnvelope.php | packages/ddd-core/src/Application/Events/IntegrationEnvelope.php | TangibleDDD\Application\Events\IntegrationEnvelope | keep | core | - |
| ddd-src/Application/Events/PublishedFacts.php | packages/ddd-core/src/Application/Events/PublishedFacts.php | TangibleDDD\Application\Events\PublishedFacts | keep | core | - |
| ddd-src/Application/Events/RaisesEvents.php | packages/ddd-core/src/Application/Events/RaisesEvents.php | TangibleDDD\Application\Events\RaisesEvents | keep | core | - |
| ddd-src/Application/Events/Reactions.php | packages/ddd-core/src/Application/Events/Reactions.php | TangibleDDD\Application\Events\Reactions | keep | core | - |
| ddd-src/Application/Exceptions/ApplicationException.php | packages/ddd-core/src/Application/Exceptions/ApplicationException.php | TangibleDDD\Application\Exceptions\ApplicationException | keep | core | - |
| ddd-src/Application/Exceptions/CommandDispatchedInsideCommand.php | packages/ddd-core/src/Application/Exceptions/CommandDispatchedInsideCommand.php | TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand | keep | core | - |
| ddd-src/Application/Exceptions/DomainEventAfterSealException.php | packages/ddd-core/src/Application/Exceptions/DomainEventAfterSealException.php | TangibleDDD\Application\Exceptions\DomainEventAfterSealException | keep | core | - |
| ddd-src/Application/Exceptions/SelfHandlingCommandHasNoHandler.php | packages/ddd-core/src/Application/Exceptions/SelfHandlingCommandHasNoHandler.php | TangibleDDD\Application\Exceptions\SelfHandlingCommandHasNoHandler | keep | core | - |
| ddd-src/Application/Exceptions/SelfHandlingCommandWrapsHandler.php | packages/ddd-core/src/Application/Exceptions/SelfHandlingCommandWrapsHandler.php | TangibleDDD\Application\Exceptions\SelfHandlingCommandWrapsHandler | keep | core | - |
| ddd-src/Application/Exceptions/UnresolvableHandleDependency.php | packages/ddd-core/src/Application/Exceptions/UnresolvableHandleDependency.php | TangibleDDD\Application\Exceptions\UnresolvableHandleDependency | keep | core | - |
| ddd-src/Application/Exceptions/WPErrorException.php | packages/ddd-wp/src/Application/Exceptions/WPErrorException.php | TangibleDDD\Application\Exceptions\WPErrorException | move | wp | - |
| ddd-src/Application/Infrastructure/FactDeliveredUnheard.php | packages/ddd-core/src/Application/Infrastructure/FactDeliveredUnheard.php | TangibleDDD\Application\Infrastructure\FactDeliveredUnheard | keep | core | - |
| ddd-src/Application/Infrastructure/IInfrastructureEvent.php | packages/ddd-core/src/Application/Infrastructure/IInfrastructureEvent.php | TangibleDDD\Application\Infrastructure\IInfrastructureEvent | keep | core | - |
| ddd-src/Application/Infrastructure/InfrastructureEvent.php | packages/ddd-wp/src/Application/Infrastructure/InfrastructureEvent.php | TangibleDDD\Application\Infrastructure\InfrastructureEvent | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/Infrastructure/OutboxAttemptFailed.php | packages/ddd-core/src/Application/Infrastructure/OutboxAttemptFailed.php | TangibleDDD\Application\Infrastructure\OutboxAttemptFailed | keep | core | - |
| ddd-src/Application/Infrastructure/OutboxDeadLettered.php | packages/ddd-core/src/Application/Infrastructure/OutboxDeadLettered.php | TangibleDDD\Application\Infrastructure\OutboxDeadLettered | keep | core | - |
| ddd-src/Application/Infrastructure/ProcessFailed.php | packages/ddd-core/src/Application/Infrastructure/ProcessFailed.php | TangibleDDD\Application\Infrastructure\ProcessFailed | keep | core | - |
| ddd-src/Application/Infrastructure/WorkflowFailed.php | packages/ddd-core/src/Application/Infrastructure/WorkflowFailed.php | TangibleDDD\Application\Infrastructure\WorkflowFailed | keep | core | - |
| ddd-src/Application/Logging/Redactor.php | packages/ddd-core/src/Application/Logging/Redactor.php | TangibleDDD\Application\Logging\Redactor | keep | core | - |
| ddd-src/Application/Outbox/IOutboxPublisher.php | packages/ddd-core/src/Application/Outbox/IOutboxPublisher.php | TangibleDDD\Application\Outbox\IOutboxPublisher | keep | core | - |
| ddd-src/Application/Outbox/OutboxConfig.php | packages/ddd-wp/src/Application/Outbox/OutboxConfig.php | TangibleDDD\Application\Outbox\OutboxConfig | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/Outbox/OutboxEntry.php | packages/ddd-core/src/Application/Outbox/OutboxEntry.php | TangibleDDD\Application\Outbox\OutboxEntry | keep | core | - |
| ddd-src/Application/Persistence/TransactionMiddleware.php | packages/ddd-wp/src/Application/Persistence/TransactionMiddleware.php | TangibleDDD\Application\Persistence\TransactionMiddleware | split-r2 | wp | - |
| ddd-src/Application/Process/Async.php | packages/ddd-core/src/Application/Process/Async.php | TangibleDDD\Application\Process\Async | keep | core | - |
| ddd-src/Application/Process/AwaitAll.php | packages/ddd-core/src/Application/Process/AwaitAll.php | TangibleDDD\Application\Process\AwaitAll | keep | core | - |
| ddd-src/Application/Process/AwaitEvent.php | packages/ddd-core/src/Application/Process/AwaitEvent.php | TangibleDDD\Application\Process\AwaitEvent | keep | core | - |
| ddd-src/Application/Process/AwaitedEventNotRegistered.php | packages/ddd-core/src/Application/Process/AwaitedEventNotRegistered.php | TangibleDDD\Application\Process\AwaitedEventNotRegistered | keep | core | - |
| ddd-src/Application/Process/Awaits.php | packages/ddd-core/src/Application/Process/Awaits.php | TangibleDDD\Application\Process\Awaits | keep | core | - |
| ddd-src/Application/Process/Compensates.php | packages/ddd-core/src/Application/Process/Compensates.php | TangibleDDD\Application\Process\Compensates | keep | core | - |
| ddd-src/Application/Process/IAwaitMechanism.php | packages/ddd-core/src/Application/Process/IAwaitMechanism.php | TangibleDDD\Application\Process\IAwaitMechanism | keep | core | - |
| ddd-src/Application/Process/LongProcess.php | packages/ddd-core/src/Application/Process/LongProcess.php | TangibleDDD\Application\Process\LongProcess | keep | core | - |
| ddd-src/Application/Process/LongProcessCatalog.php | packages/ddd-core/src/Application/Process/LongProcessCatalog.php | TangibleDDD\Application\Process\LongProcessCatalog | keep | core | - |
| ddd-src/Application/Process/ProcessRunner.php | packages/ddd-wp/src/Application/Process/ProcessRunner.php | TangibleDDD\Application\Process\ProcessRunner | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Application/Process/ProcessStartedInsideCommand.php | packages/ddd-core/src/Application/Process/ProcessStartedInsideCommand.php | TangibleDDD\Application\Process\ProcessStartedInsideCommand | keep | core | - |
| ddd-src/Application/Process/ProcessStartedInsideProcess.php | packages/ddd-core/src/Application/Process/ProcessStartedInsideProcess.php | TangibleDDD\Application\Process\ProcessStartedInsideProcess | keep | core | - |
| ddd-src/Application/Process/ProcessSteps.php | packages/ddd-core/src/Application/Process/ProcessSteps.php | TangibleDDD\Application\Process\ProcessSteps | keep | core | - |
| ddd-src/Application/Process/RescheduleAware.php | packages/ddd-core/src/Application/Process/RescheduleAware.php | TangibleDDD\Application\Process\RescheduleAware | keep | core | - |
| ddd-src/Application/Process/Result.php | packages/ddd-core/src/Application/Process/Result.php | TangibleDDD\Application\Process\Result | keep | core | - |
| ddd-src/Application/Process/StartsOn.php | packages/ddd-core/src/Application/Process/StartsOn.php | TangibleDDD\Application\Process\StartsOn | keep | core | - |
| ddd-src/Application/Queries/IQuery.php | packages/ddd-core/src/Application/Queries/IQuery.php | TangibleDDD\Application\Queries\IQuery | keep | core | - |
| ddd-src/Application/Queries/SelfHandlingQuery.php | packages/ddd-core/src/Application/Queries/SelfHandlingQuery.php | TangibleDDD\Application\Queries\SelfHandlingQuery | keep | core | - |
| ddd-src/Application/QueryHandlers/IQueryHandler.php | packages/ddd-core/src/Application/QueryHandlers/IQueryHandler.php | TangibleDDD\Application\QueryHandlers\IQueryHandler | keep | core | - |
| ddd-src/Application/Support/ConsumerTables.php | packages/ddd-wp/src/Application/Support/ConsumerTables.php | TangibleDDD\Application\Support\ConsumerTables | move | wp | - |
| ddd-src/Application/Tracing/TraceStitcher.php | packages/ddd-core/src/Application/Tracing/TraceStitcher.php | TangibleDDD\Application\Tracing\TraceStitcher | keep | core | - |
| ddd-src/Application/Traits/DTOConstructibleTrait.php | packages/ddd-core/src/Application/Traits/DTOConstructibleTrait.php | TangibleDDD\Application\Traits\DTOConstructibleTrait | keep | core | - |
| ddd-src/Application/TypedLists/JsonLifecycleValueList.php | packages/ddd-core/src/Application/TypedLists/JsonLifecycleValueList.php | TangibleDDD\Application\TypedLists\JsonLifecycleValueList | keep | core | - |
| ddd-src/Domain/BehaviourWorkflow.php | packages/ddd-core/src/Domain/BehaviourWorkflow.php | TangibleDDD\Domain\BehaviourWorkflow | keep | core | - |
| ddd-src/Domain/DataRendering/AbstractValueRenderer.php | packages/ddd-core/src/Domain/DataRendering/AbstractValueRenderer.php | TangibleDDD\Domain\DataRendering\AbstractValueRenderer | keep | core | - |
| ddd-src/Domain/DataRendering/NullValueRenderer.php | packages/ddd-core/src/Domain/DataRendering/NullValueRenderer.php | TangibleDDD\Domain\DataRendering\NullValueRenderer | keep | core | - |
| ddd-src/Domain/DataRendering/TangibleFieldsRenderer.php | packages/ddd-wp/src/Domain/DataRendering/TangibleFieldsRenderer.php | TangibleDDD\Domain\DataRendering\TangibleFieldsRenderer | move | wp | - |
| ddd-src/Domain/Events/AlreadyIntegrated.php | packages/ddd-core/src/Domain/Events/AlreadyIntegrated.php | TangibleDDD\Domain\Events\AlreadyIntegrated | keep | core | - |
| ddd-src/Domain/Events/DomainEvent.php | packages/ddd-core/src/Domain/Events/DomainEvent.php | TangibleDDD\Domain\Events\DomainEvent | keep | core | - |
| ddd-src/Domain/Events/Event.php | packages/ddd-core/src/Domain/Events/Event.php | TangibleDDD\Domain\Events\Event | keep | core | - |
| ddd-src/Domain/Events/IAnnouncesIntegration.php | packages/ddd-core/src/Domain/Events/IAnnouncesIntegration.php | TangibleDDD\Domain\Events\IAnnouncesIntegration | keep | core | - |
| ddd-src/Domain/Events/IDomainEvent.php | packages/ddd-core/src/Domain/Events/IDomainEvent.php | TangibleDDD\Domain\Events\IDomainEvent | keep | core | - |
| ddd-src/Domain/Events/IEventFromArgs.php | packages/ddd-core/src/Domain/Events/IEventFromArgs.php | TangibleDDD\Domain\Events\IEventFromArgs | keep | core | - |
| ddd-src/Domain/Events/IIntegrationEvent.php | packages/ddd-core/src/Domain/Events/IIntegrationEvent.php | TangibleDDD\Domain\Events\IIntegrationEvent | keep | core | - |
| ddd-src/Domain/Events/IntegrationBehaviour.php | packages/ddd-core/src/Domain/Events/IntegrationBehaviour.php | TangibleDDD\Domain\Events\IntegrationBehaviour | keep | core | - |
| ddd-src/Domain/Events/IntegrationEvent.php | packages/ddd-core/src/Domain/Events/IntegrationEvent.php | TangibleDDD\Domain\Events\IntegrationEvent | keep | core | - |
| ddd-src/Domain/Events/NonReversibleValue.php | packages/ddd-core/src/Domain/Events/NonReversibleValue.php | TangibleDDD\Domain\Events\NonReversibleValue | keep | core | - |
| ddd-src/Domain/Events/Op.php | packages/ddd-core/src/Domain/Events/Op.php | TangibleDDD\Domain\Events\Op | keep | core | - |
| ddd-src/Domain/Events/Touches.php | packages/ddd-core/src/Domain/Events/Touches.php | TangibleDDD\Domain\Events\Touches | keep | core | - |
| ddd-src/Domain/Events/TouchesNonAggregate.php | packages/ddd-core/src/Domain/Events/TouchesNonAggregate.php | TangibleDDD\Domain\Events\TouchesNonAggregate | keep | core | - |
| ddd-src/Domain/Exceptions/BusinessConstraintException.php | packages/ddd-core/src/Domain/Exceptions/BusinessConstraintException.php | TangibleDDD\Domain\Exceptions\BusinessConstraintException | keep | core | - |
| ddd-src/Domain/Exceptions/InvariantException.php | packages/ddd-core/src/Domain/Exceptions/InvariantException.php | TangibleDDD\Domain\Exceptions\InvariantException | keep | core | - |
| ddd-src/Domain/Exceptions/RefNotFoundException.php | packages/ddd-core/src/Domain/Exceptions/RefNotFoundException.php | TangibleDDD\Domain\Exceptions\RefNotFoundException | keep | core | - |
| ddd-src/Domain/Exceptions/TypeMismatchException.php | packages/ddd-core/src/Domain/Exceptions/TypeMismatchException.php | TangibleDDD\Domain\Exceptions\TypeMismatchException | keep | core | - |
| ddd-src/Domain/Exceptions/WorkflowException.php | packages/ddd-core/src/Domain/Exceptions/WorkflowException.php | TangibleDDD\Domain\Exceptions\WorkflowException | keep | core | - |
| ddd-src/Domain/Repositories/IBehaviourWorkflowRepository.php | packages/ddd-core/src/Domain/Repositories/IBehaviourWorkflowRepository.php | TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository | keep | core | - |
| ddd-src/Domain/Repositories/IWorkItemRepository.php | packages/ddd-core/src/Domain/Repositories/IWorkItemRepository.php | TangibleDDD\Domain\Repositories\IWorkItemRepository | keep | core | - |
| ddd-src/Domain/Services/IDomainService.php | packages/ddd-core/src/Domain/Services/IDomainService.php | TangibleDDD\Domain\Services\IDomainService | keep | core | - |
| ddd-src/Domain/Shared/Aggregate.php | packages/ddd-core/src/Domain/Shared/Aggregate.php | TangibleDDD\Domain\Shared\Aggregate | keep | core | - |
| ddd-src/Domain/Shared/DirectJsonLifecycleValue.php | packages/ddd-core/src/Domain/Shared/DirectJsonLifecycleValue.php | TangibleDDD\Domain\Shared\DirectJsonLifecycleValue | keep | core | - |
| ddd-src/Domain/Shared/Entity.php | packages/ddd-core/src/Domain/Shared/Entity.php | TangibleDDD\Domain\Shared\Entity | keep | core | - |
| ddd-src/Domain/Shared/IDTOConstructible.php | packages/ddd-core/src/Domain/Shared/IDTOConstructible.php | TangibleDDD\Domain\Shared\IDTOConstructible | keep | core | - |
| ddd-src/Domain/Shared/IJsonSerializable.php | packages/ddd-core/src/Domain/Shared/IJsonSerializable.php | TangibleDDD\Domain\Shared\IJsonSerializable | keep | core | - |
| ddd-src/Domain/Shared/IRecordsDomainEvents.php | packages/ddd-core/src/Domain/Shared/IRecordsDomainEvents.php | TangibleDDD\Domain\Shared\IRecordsDomainEvents | keep | core | - |
| ddd-src/Domain/Shared/IValueObject.php | packages/ddd-core/src/Domain/Shared/IValueObject.php | TangibleDDD\Domain\Shared\IValueObject | keep | core | - |
| ddd-src/Domain/Shared/IValueRenderer.php | packages/ddd-core/src/Domain/Shared/IValueRenderer.php | TangibleDDD\Domain\Shared\IValueRenderer | keep | core | - |
| ddd-src/Domain/Shared/JsonLifecycleValue.php | packages/ddd-core/src/Domain/Shared/JsonLifecycleValue.php | TangibleDDD\Domain\Shared\JsonLifecycleValue | keep | core | - |
| ddd-src/Domain/Shared/RecordsDomainEvents.php | packages/ddd-core/src/Domain/Shared/RecordsDomainEvents.php | TangibleDDD\Domain\Shared\RecordsDomainEvents | keep | core | - |
| ddd-src/Domain/Shared/Uuid.php | packages/ddd-core/src/Domain/Shared/Uuid.php | TangibleDDD\Domain\Shared\Uuid | keep | core | - |
| ddd-src/Domain/Shared/ValueObject.php | packages/ddd-core/src/Domain/Shared/ValueObject.php | TangibleDDD\Domain\Shared\ValueObject | keep | core | - |
| ddd-src/Domain/Shared/assert.php | packages/ddd-core/src/Domain/Shared/assert.php | TangibleDDD\Domain\Shared\assert_type() (function file) | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/BaseBehaviourConfig.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/BaseBehaviourConfig.php | TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/BatchableBehaviourConfig.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/BatchableBehaviourConfig.php | TangibleDDD\Domain\ValueObjects\Behaviours\BatchableBehaviourConfig | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/BehaviourExecutionResult.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/BehaviourExecutionResult.php | TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/BehaviourExecutionStatus.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/BehaviourExecutionStatus.php | TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/ISagaBehaviour.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/ISagaBehaviour.php | TangibleDDD\Domain\ValueObjects\Behaviours\ISagaBehaviour | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/WorkItem.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/WorkItem.php | TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/WorkItemList.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/WorkItemList.php | TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList | keep | core | - |
| ddd-src/Domain/ValueObjects/Behaviours/WorkItemStatus.php | packages/ddd-core/src/Domain/ValueObjects/Behaviours/WorkItemStatus.php | TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus | keep | core | - |
| ddd-src/Domain/ValueObjects/EntityAttributes/BaseAssociatedEntityAttributes.php | packages/ddd-core/src/Domain/ValueObjects/EntityAttributes/BaseAssociatedEntityAttributes.php | TangibleDDD\Domain\ValueObjects\EntityAttributes\BaseAssociatedEntityAttributes | keep | core | - |
| ddd-src/Infra/Config.php | packages/ddd-wp/src/Infra/Config.php | TangibleDDD\Infra\Config | move | wp | - |
| ddd-src/Infra/Consumers/ConsumerHandle.php | packages/ddd-core/src/Infra/Consumers/ConsumerHandle.php | TangibleDDD\Infra\Consumers\ConsumerHandle | split-core | core | - |
| ddd-src/Infra/Consumers/ConsumerRegistry.php | packages/ddd-core/src/Infra/Consumers/ConsumerRegistry.php | TangibleDDD\Infra\Consumers\ConsumerRegistry | keep | core | add() widened to IConsumerIdentity (register 3.1) |
| ddd-src/Infra/Consumers/IntegrationHookName.php | packages/ddd-core/src/Infra/Consumers/IntegrationHookName.php | TangibleDDD\Infra\Consumers\IntegrationHookName | keep | core | - |
| ddd-src/Infra/Consumers/NoConsumerOwnsClass.php | packages/ddd-core/src/Infra/Consumers/NoConsumerOwnsClass.php | TangibleDDD\Infra\Consumers\NoConsumerOwnsClass | keep | core | - |
| ddd-src/Infra/DDDConfig.php | packages/ddd-wp/src/Infra/DDDConfig.php | TangibleDDD\Infra\DDDConfig | move | wp | - |
| ddd-src/Infra/DependencyInjection/DDDCompilerPasses.php | packages/ddd-core/src/Infra/DependencyInjection/DDDCompilerPasses.php | TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses | keep | core | DI bridge (X4) |
| ddd-src/Infra/DependencyInjection/LongProcessCatalogPass.php | packages/ddd-core/src/Infra/DependencyInjection/LongProcessCatalogPass.php | TangibleDDD\Infra\DependencyInjection\LongProcessCatalogPass | keep | core | DI bridge (X4) |
| ddd-src/Infra/Exceptions/IncorrectUsageException.php | packages/ddd-core/src/Infra/Exceptions/IncorrectUsageException.php | TangibleDDD\Infra\Exceptions\IncorrectUsageException | keep | core | - |
| ddd-src/Infra/Exceptions/LockingException.php | packages/ddd-core/src/Infra/Exceptions/LockingException.php | TangibleDDD\Infra\Exceptions\LockingException | keep | core | - |
| ddd-src/Infra/Exceptions/QueryException.php | packages/ddd-core/src/Infra/Exceptions/QueryException.php | TangibleDDD\Infra\Exceptions\QueryException | keep | core | - |
| ddd-src/Infra/IDDDConfig.php | packages/ddd-core/src/Infra/IDDDConfig.php | TangibleDDD\Infra\IDDDConfig | split-core | core | - |
| ddd-src/Infra/IOutboxRepository.php | packages/ddd-core/src/Infra/IOutboxRepository.php | TangibleDDD\Infra\IOutboxRepository | keep | core | - |
| ddd-src/Infra/IProcessRepository.php | packages/ddd-core/src/Infra/IProcessRepository.php | TangibleDDD\Infra\IProcessRepository | keep | core | - |
| ddd-src/Infra/Persistence/BehaviourWorkflowRepository.php | packages/ddd-wp/src/Infra/Persistence/BehaviourWorkflowRepository.php | TangibleDDD\Infra\Persistence\BehaviourWorkflowRepository | move | wp | - |
| ddd-src/Infra/Persistence/OutboxRepository.php | packages/ddd-wp/src/Infra/Persistence/OutboxRepository.php | TangibleDDD\Infra\Persistence\OutboxRepository | move | wp | - |
| ddd-src/Infra/Persistence/ProcessRepository.php | packages/ddd-wp/src/Infra/Persistence/ProcessRepository.php | TangibleDDD\Infra\Persistence\ProcessRepository | move | wp | - |
| ddd-src/Infra/Persistence/Select/ISelect.php | packages/ddd-wp/src/Infra/Persistence/Select/ISelect.php | TangibleDDD\Infra\Persistence\Select\ISelect | move | wp | - |
| ddd-src/Infra/Persistence/Select/QueryBuilderSelect.php | packages/ddd-wp/src/Infra/Persistence/Select/QueryBuilderSelect.php | TangibleDDD\Infra\Persistence\Select\QueryBuilderSelect | move | wp | - |
| ddd-src/Infra/Persistence/Shared/IPersistsAggregates.php | packages/ddd-core/src/Infra/Persistence/Shared/IPersistsAggregates.php | TangibleDDD\Infra\Persistence\Shared\IPersistsAggregates | keep | core | - |
| ddd-src/Infra/Persistence/Shared/ISearchableRepository.php | packages/ddd-wp/src/Infra/Persistence/Shared/ISearchableRepository.php | TangibleDDD\Infra\Persistence\Shared\ISearchableRepository | move | wp | - |
| ddd-src/Infra/Persistence/Shared/PersistsAggregatesRepository.php | packages/ddd-core/src/Infra/Persistence/Shared/PersistsAggregatesRepository.php | TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository | keep | core | - |
| ddd-src/Infra/Persistence/Shared/RepositorySearchResult.php | packages/ddd-wp/src/Infra/Persistence/Shared/RepositorySearchResult.php | TangibleDDD\Infra\Persistence\Shared\RepositorySearchResult | move | wp | - |
| ddd-src/Infra/Persistence/WordPress/WordPressRepository.php | packages/ddd-wp/src/Infra/Persistence/WordPress/WordPressRepository.php | TangibleDDD\Infra\Persistence\WordPress\WordPressRepository | move | wp | - |
| ddd-src/Infra/Persistence/WorkItemRepository.php | packages/ddd-wp/src/Infra/Persistence/WorkItemRepository.php | TangibleDDD\Infra\Persistence\WorkItemRepository | move | wp | - |
| ddd-src/Infra/Services/ActionSchedulerOutboxPublisher.php | packages/ddd-wp/src/Infra/Services/ActionSchedulerOutboxPublisher.php | TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher | move | wp | - |
| ddd-src/Infra/Services/FactPublishedInsideProcess.php | packages/ddd-core/src/Infra/Services/FactPublishedInsideProcess.php | TangibleDDD\Infra\Services\FactPublishedInsideProcess | keep | core | - |
| ddd-src/Infra/Services/OutboxIntegrationEventBus.php | packages/ddd-wp/src/Infra/Services/OutboxIntegrationEventBus.php | TangibleDDD\Infra\Services\OutboxIntegrationEventBus | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Infra/Services/OutboxProcessor.php | packages/ddd-wp/src/Infra/Services/OutboxProcessor.php | TangibleDDD\Infra\Services\OutboxProcessor | split-deferred | wp | round 2: core half onto ports |
| ddd-src/Infra/Services/ProcessingResult.php | packages/ddd-core/src/Infra/Services/ProcessingResult.php | TangibleDDD\Infra\Services\ProcessingResult | keep | core | - |
| ddd-src/Infra/Services/RoutingOutboxPublisher.php | packages/ddd-wp/src/Infra/Services/RoutingOutboxPublisher.php | TangibleDDD\Infra\Services\RoutingOutboxPublisher | move | wp | - |
| ddd-src/Infra/Services/WordPressEventDispatcher.php | packages/ddd-wp/src/Infra/Services/WordPressEventDispatcher.php | TangibleDDD\Infra\Services\WordPressEventDispatcher | move | wp | - |
| ddd-src/Infra/Shared/IntList.php | packages/ddd-core/src/Infra/Shared/IntList.php | TangibleDDD\Infra\Shared\IntList | keep | core | - |
| ddd-src/Infra/Shared/StringList.php | packages/ddd-core/src/Infra/Shared/StringList.php | TangibleDDD\Infra\Shared\StringList | keep | core | - |
| ddd-src/Infra/Shared/TypedList.php | packages/ddd-core/src/Infra/Shared/TypedList.php | TangibleDDD\Infra\Shared\TypedList | keep | core | - |
| ddd-src/Testing/IntegrationConformance.php | packages/ddd-core/src/Testing/IntegrationConformance.php | TangibleDDD\Testing\IntegrationConformance | split-core | core | - |
| ddd-wordpress/Admin/Dashboard/ActionDispatcher.php | packages/ddd-wp/wordpress/Admin/Dashboard/ActionDispatcher.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/AdminPage.php | packages/ddd-wp/wordpress/Admin/Dashboard/AdminPage.php | - | wordpress | wp | asset base and template path updated |
| ddd-wordpress/Admin/Dashboard/ConsumerCatalog.php | packages/ddd-wp/wordpress/Admin/Dashboard/ConsumerCatalog.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/ConsumerDefinition.php | packages/ddd-wp/wordpress/Admin/Dashboard/ConsumerDefinition.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Dashboard.php | packages/ddd-wp/wordpress/Admin/Dashboard/Dashboard.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Database.php | packages/ddd-wp/wordpress/Admin/Dashboard/Database.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/HeartbeatController.php | packages/ddd-wp/wordpress/Admin/Dashboard/HeartbeatController.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/PrefixOnlyConfig.php | packages/ddd-wp/wordpress/Admin/Dashboard/PrefixOnlyConfig.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/BiographyQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/BiographyQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/CommandAuditQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/CommandAuditQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/DeadLetterQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/DeadLetterQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/LiveQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/LiveQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/LoomPresenter.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/LoomPresenter.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/MetricsQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/MetricsQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/OutboxQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/OutboxQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/ProcessQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/ProcessQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/TraceFragmentReader.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/TraceFragmentReader.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/TraceTimelinePresenter.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/TraceTimelinePresenter.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/TracesQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/TracesQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/UnifiedTraceQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/UnifiedTraceQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/Query/WorkflowQuery.php | packages/ddd-wp/wordpress/Admin/Dashboard/Query/WorkflowQuery.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/RestController.php | packages/ddd-wp/wordpress/Admin/Dashboard/RestController.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/WpDatabase.php | packages/ddd-wp/wordpress/Admin/Dashboard/WpDatabase.php | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/assets/dashboard.css | packages/ddd-wp/wordpress/Admin/Dashboard/assets/dashboard.css | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/assets/dashboard.js | packages/ddd-wp/wordpress/Admin/Dashboard/assets/dashboard.js | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/assets/trace-island.js | packages/ddd-wp/wordpress/Admin/Dashboard/assets/trace-island.js | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/assets/vendor/htm.js | packages/ddd-wp/wordpress/Admin/Dashboard/assets/vendor/htm.js | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/assets/vendor/preact-hooks.umd.js | packages/ddd-wp/wordpress/Admin/Dashboard/assets/vendor/preact-hooks.umd.js | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/assets/vendor/preact.min.js | packages/ddd-wp/wordpress/Admin/Dashboard/assets/vendor/preact.min.js | - | wordpress | wp | - |
| ddd-wordpress/Admin/Dashboard/template.php | packages/ddd-wp/wordpress/Admin/Dashboard/template.php | - | wordpress | wp | - |
| ddd-wordpress/audit.php | packages/ddd-wp/wordpress/audit.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/cli/class-ddd-command.php | packages/ddd-wp/wordpress/cli/class-ddd-command.php | - | wordpress | wp | classmap |
| ddd-wordpress/cli/register.php | packages/ddd-wp/wordpress/cli/register.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/dashboard.php | packages/ddd-wp/wordpress/dashboard.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/db.php | packages/ddd-wp/wordpress/db.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/di/services.yaml | packages/ddd-wp/wordpress/di/services.yaml | - | wordpress | wp | - |
| ddd-wordpress/di/tactician.yaml | packages/ddd-wp/wordpress/di/tactician.yaml | - | wordpress | wp | fixed to real classes, compile-tested (A F-28, B26) |
| ddd-wordpress/hooks.php | packages/ddd-wp/wordpress/hooks.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/infrastructure-events.php | packages/ddd-wp/wordpress/infrastructure-events.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/integration-events.php | packages/ddd-wp/wordpress/integration-events.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/locking.php | packages/ddd-wp/wordpress/locking.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/migrations.php | packages/ddd-wp/wordpress/migrations.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/modules.php | packages/ddd-wp/wordpress/modules.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/secret.php | packages/ddd-wp/wordpress/secret.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/self/HandlerClassNameInflector.php | packages/ddd-wp/wordpress/self/HandlerClassNameInflector.php | - | wordpress | wp | classmap |
| ddd-wordpress/self/index.php | packages/ddd-wp/wordpress/self/index.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/self/services.yaml | packages/ddd-wp/wordpress/self/services.yaml | - | wordpress | wp | handlers listed explicitly (B6) |
| ddd-wordpress/self/tactician.yaml | packages/ddd-wp/wordpress/self/tactician.yaml | - | wordpress | wp | - |
| ddd-wordpress/tables.php | packages/ddd-wp/wordpress/tables.php | - | wordpress | wp | forwarding shim left at old path |
| ddd-wordpress/touches.php | packages/ddd-wp/wordpress/touches.php | - | wordpress | wp | forwarding shim left at old path |
