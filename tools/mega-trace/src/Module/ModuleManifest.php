<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Module;

use Tangible\Cred\MegaTrace\Application\BehaviourWorkflows\IssuanceRoutine;
use Tangible\Cred\MegaTrace\Application\IntegrationListeners\FleetPolicies as CredPolicies;
use Tangible\Cred\MegaTrace\Application\Process\CredentialIssuanceProcess;
use Tangible\Cred\MegaTrace\Domain\Events\CompliancePortfolioOpened;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialEvidenceVerified;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialNotificationQueued;
use Tangible\Cred\MegaTrace\Application\Commands\RunIssuanceRoutine;
use Tangible\Cred\MegaTrace\Domain\Events\IssuanceRoutineItemCompleted;
use Tangible\Cred\MegaTrace\Domain\Events\IssuanceRoutineRescheduled;
use Tangible\Cred\MegaTrace\Domain\Events\PortfolioExported;
use Tangible\Cred\MegaTrace\Domain\Events\ProvisionalCompetencyRecorded;
use Tangible\Cred\MegaTrace\Domain\Events\SupervisorAttestationReceived;
use Tangible\Datastream\MegaTrace\Application\IntegrationListeners\FleetPolicies as DatastreamPolicies;
use Tangible\Datastream\MegaTrace\Application\Process\EvidenceExportProcess;
use Tangible\Datastream\MegaTrace\Domain\Events\CredentialExportPrepared;
use Tangible\Datastream\MegaTrace\Domain\Events\CredentialRegistrySynchronized;
use Tangible\Datastream\MegaTrace\Domain\Events\EvidencePackageCreated;
use Tangible\Datastream\MegaTrace\Domain\Events\EvidenceSnapshotCaptured;
use Tangible\Datastream\MegaTrace\Domain\Events\EvidenceStreamOpened;
use Tangible\Datastream\MegaTrace\Domain\Events\RegistryReceiptReceived;
use Tangible\LMS\MegaTrace\Application\IntegrationListeners\FleetPolicies as LmsPolicies;
use Tangible\LMS\MegaTrace\Application\Process\CertificationJourneyProcess;
use Tangible\LMS\MegaTrace\Domain\Events\AdaptiveModulesUnlocked;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyLaunched;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationRecordArchived;
use Tangible\LMS\MegaTrace\Domain\Events\CredentialAttachedToJourney;
use Tangible\LMS\MegaTrace\Domain\Events\JourneyPlanRecorded;
use Tangible\LMS\MegaTrace\Domain\Events\LearningPathPersonalized;
use Tangible\Quiz\MegaTrace\Application\IntegrationListeners\FleetPolicies as QuizPolicies;
use Tangible\Quiz\MegaTrace\Application\Process\AdaptiveAssessmentProcess;
use Tangible\Quiz\MegaTrace\Domain\Events\AssessmentFinalized;
use Tangible\Quiz\MegaTrace\Domain\Events\AssessmentRecordSealed;
use Tangible\Quiz\MegaTrace\Domain\Events\CapstoneAttemptSubmitted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentGraded;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentPrepared;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAttemptOpened;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAttemptSubmitted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticSignalsAnalyzed;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Application\Persistence\TransactionMiddleware;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;

final class ModuleManifest
{
    /** @return list<ModuleDefinition> */
    public static function definitions(): array
    {
        return [
            new ModuleDefinition(
                host_prefix: 'tangible_lms',
                namespace_root: 'Tangible\\LMS\\MegaTrace',
                transaction_service_id: 'Tangible\\LMS\\Application\\Middleware\\DoctrineTransactionMiddleware',
                services: [LmsPolicies::class],
                processes: [CertificationJourneyProcess::class],
                bridged_services: [IIntegrationEventBus::class],
                events: [
                    CertificationJourneyLaunched::class,
                    JourneyPlanRecorded::class,
                    LearningPathPersonalized::class,
                    AdaptiveModulesUnlocked::class,
                    CertificationJourneyCompleted::class,
                    CredentialAttachedToJourney::class,
                    CertificationRecordArchived::class,
                ],
            ),
            new ModuleDefinition(
                host_prefix: 'tangible_quiz',
                namespace_root: 'Tangible\\Quiz\\MegaTrace',
                transaction_service_id: 'Tangible\\Quiz\\Application\\Middleware\\DoctrineTransactionMiddleware',
                services: [QuizPolicies::class],
                processes: [AdaptiveAssessmentProcess::class],
                bridged_services: [IIntegrationEventBus::class],
                events: [
                    DiagnosticAssessmentPrepared::class,
                    DiagnosticAttemptOpened::class,
                    DiagnosticAttemptSubmitted::class,
                    DiagnosticAssessmentGraded::class,
                    DiagnosticSignalsAnalyzed::class,
                    CapstoneAttemptSubmitted::class,
                    AssessmentFinalized::class,
                    AssessmentRecordSealed::class,
                ],
            ),
            new ModuleDefinition(
                host_prefix: 'tgbl_cred',
                namespace_root: 'Tangible\\Cred\\MegaTrace',
                transaction_service_id: TransactionMiddleware::class,
                services: [CredPolicies::class, IssuanceRoutine::class],
                processes: [CredentialIssuanceProcess::class],
                bridged_services: [
                    IIntegrationEventBus::class,
                    IBehaviourWorkflowRepository::class,
                    IWorkItemRepository::class,
                ],
                events: [
                    CompliancePortfolioOpened::class,
                    ProvisionalCompetencyRecorded::class,
                    CredentialEvidenceVerified::class,
                    IssuanceRoutineItemCompleted::class,
                    IssuanceRoutineRescheduled::class,
                    SupervisorAttestationReceived::class,
                    CredentialIssued::class,
                    CredentialNotificationQueued::class,
                    PortfolioExported::class,
                ],
                handlers: [
                    RunIssuanceRoutine::class => IssuanceRoutine::class,
                ],
            ),
            new ModuleDefinition(
                host_prefix: 'tangible_datastream',
                namespace_root: 'Tangible\\Datastream\\MegaTrace',
                transaction_service_id: TransactionMiddleware::class,
                services: [DatastreamPolicies::class],
                processes: [EvidenceExportProcess::class],
                bridged_services: [IIntegrationEventBus::class],
                events: [
                    EvidenceStreamOpened::class,
                    EvidenceSnapshotCaptured::class,
                    CredentialExportPrepared::class,
                    EvidencePackageCreated::class,
                    RegistryReceiptReceived::class,
                    CredentialRegistrySynchronized::class,
                ],
            ),
        ];
    }
}
