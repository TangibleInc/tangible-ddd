<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tangible\Cred\MegaTrace\Application\Process\CredentialIssuanceProcess;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Cred\MegaTrace\Domain\Events\SupervisorAttestationReceived;
use Tangible\Datastream\MegaTrace\Application\Process\EvidenceExportProcess;
use Tangible\Datastream\MegaTrace\Domain\Events\RegistryReceiptReceived;
use Tangible\LMS\MegaTrace\Application\Process\CertificationJourneyProcess;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyLaunched;
use Tangible\Quiz\MegaTrace\Application\Process\AdaptiveAssessmentProcess;
use Tangible\Quiz\MegaTrace\Domain\Events\CapstoneAttemptSubmitted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentPrepared;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAttemptSubmitted;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Events\Touches;
use TangibleDDD\MegaTrace\Module\ModuleManifest;

final class MegaTraceTopologyTest extends TestCase
{
    /**
     * @param class-string $process
     * @param class-string $starts_on
     * @param list<class-string> $awaits
     */
    #[DataProvider('process_topology')]
    public function test_processes_declare_their_ignition_and_wake_facts(
        string $process,
        string $starts_on,
        array $awaits,
    ): void {
        $reflection = new ReflectionClass($process);
        $start_attributes = $reflection->getAttributes(StartsOn::class);
        $await_attributes = $reflection->getAttributes(Awaits::class);

        self::assertSame([$starts_on], array_map(
            static fn ($attribute): string => $attribute->newInstance()->event_class,
            $start_attributes,
        ));
        self::assertSame($awaits, array_map(
            static fn ($attribute): string => $attribute->newInstance()->event_class,
            $await_attributes,
        ));
    }

    /** @return iterable<string, array{class-string, class-string, list<class-string>}> */
    public static function process_topology(): iterable
    {
        yield 'lms learning journey' => [
            CertificationJourneyProcess::class,
            CertificationJourneyLaunched::class,
            [DiagnosticAttemptSubmitted::class, CapstoneAttemptSubmitted::class],
        ];
        yield 'quiz adaptive assessment' => [
            AdaptiveAssessmentProcess::class,
            DiagnosticAssessmentPrepared::class,
            [DiagnosticAttemptSubmitted::class, CapstoneAttemptSubmitted::class],
        ];
        yield 'cred credential issuance' => [
            CredentialIssuanceProcess::class,
            CertificationJourneyCompleted::class,
            [SupervisorAttestationReceived::class],
        ];
        yield 'datastream registry export' => [
            EvidenceExportProcess::class,
            CredentialIssued::class,
            [RegistryReceiptReceived::class],
        ];
    }

    public function test_every_scenario_fact_declares_at_least_one_aggregate_touch(): void
    {
        $definitions = ModuleManifest::definitions();

        self::assertSame(
            ['tangible_lms', 'tangible_quiz', 'tgbl_cred', 'tangible_datastream'],
            array_map(static fn ($definition): string => $definition->host_prefix, $definitions),
        );

        foreach ($definitions as $definition) {
            foreach ($definition->events as $event) {
                self::assertNotEmpty(
                    (new ReflectionClass($event))->getAttributes(Touches::class),
                    $event . ' must declare its biography footprint',
                );
            }
        }
    }
}
