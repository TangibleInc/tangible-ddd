<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tangible\Cred\MegaTrace\Application\Process\CredentialIssuanceState;
use Tangible\Datastream\MegaTrace\Application\Process\EvidenceExportState;
use Tangible\LMS\MegaTrace\Application\Process\CertificationJourneyState;
use Tangible\Quiz\MegaTrace\Application\Process\AdaptiveAssessmentState;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

final class ProcessStateRoundTripTest extends TestCase
{
    #[DataProvider('states')]
    public function test_process_state_survives_its_persistence_round_trip(DirectJsonLifecycleValue $state): void
    {
        $class = $state::class;
        $hydrated = $class::from_json($state->to_json(false));

        self::assertInstanceOf($class, $hydrated);
        self::assertEquals($state->to_json(false), $hydrated->to_json(false));
    }

    /** @return iterable<string, array{DirectJsonLifecycleValue}> */
    public static function states(): iterable
    {
        yield 'lms' => [new CertificationJourneyState('journey', 42, 7, 'attempt')];
        yield 'quiz' => [new AdaptiveAssessmentState('journey', 'attempt')];
        yield 'cred' => [new CredentialIssuanceState('journey', 42, 'portfolio', 'credential')];
        yield 'datastream' => [new EvidenceExportState('journey', 'portfolio', 'credential', 'stream', 'delivery')];
    }
}
