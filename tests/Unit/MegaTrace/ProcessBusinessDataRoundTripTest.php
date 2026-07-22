<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tangible\Cred\MegaTrace\Application\Process\CredentialIssuanceProcess;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Datastream\MegaTrace\Application\Process\EvidenceExportProcess;
use Tangible\LMS\MegaTrace\Application\Process\CertificationJourneyProcess;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyLaunched;
use Tangible\Quiz\MegaTrace\Application\Process\AdaptiveAssessmentProcess;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentPrepared;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

final class ProcessBusinessDataRoundTripTest extends TestCase
{
    #[DataProvider('processes')]
    public function test_process_constructor_is_a_persistable_scalar_schema(LongProcess $process): void
    {
        $repository = new ProcessRepository(new FakeDDDConfig());
        $extract = new ReflectionMethod($repository, 'extract_business_data');
        $recreate = new ReflectionMethod($repository, 'create_instance');

        $business_data = $extract->invoke($repository, $process);
        self::assertNotEmpty($business_data);
        foreach ($business_data as $value) {
            self::assertTrue(is_scalar($value) || $value === null);
        }

        $decoded = json_decode(json_encode($business_data), true, flags: JSON_THROW_ON_ERROR);
        $hydrated = $recreate->invoke($repository, $process::class, $decoded);

        self::assertSame($process::class, $hydrated::class);
        self::assertSame($business_data, $extract->invoke($repository, $hydrated));
    }

    /** @return iterable<string, array{LongProcess}> */
    public static function processes(): iterable
    {
        yield 'lms' => [CertificationJourneyProcess::from_event(
            new CertificationJourneyLaunched('journey', 42, 7),
        )];
        yield 'quiz' => [AdaptiveAssessmentProcess::from_event(
            new DiagnosticAssessmentPrepared('journey', 42, 'attempt'),
        )];
        yield 'cred' => [CredentialIssuanceProcess::from_event(
            new CertificationJourneyCompleted('journey', 42, 94),
        )];
        yield 'datastream' => [EvidenceExportProcess::from_event(
            new CredentialIssued('journey', 42, 'portfolio', 'credential'),
        )];
    }
}
