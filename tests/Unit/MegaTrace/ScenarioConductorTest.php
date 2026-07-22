<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\TestCase;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentGraded;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentPrepared;
use TangibleDDD\MegaTrace\Scenario\ScenarioConductor;
use TangibleDDD\MegaTrace\Scenario\ScenarioCoordinator;
use TangibleDDD\MegaTrace\Scenario\ScenarioPlan;
use TangibleDDD\MegaTrace\Scenario\ScenarioQueue;

final class ScenarioConductorTest extends TestCase
{
    public function test_each_domain_milestone_schedules_only_its_next_external_boundary(): void
    {
        $queue = new ConductorRecordingQueue();
        $conductor = new ScenarioConductor($queue, static fn (): int => 1_000);

        $conductor->assessment_prepared(new DiagnosticAssessmentPrepared('journey', 42, 'attempt'));
        $conductor->assessment_graded(new DiagnosticAssessmentGraded('journey', 42, 'attempt', 82));
        $conductor->journey_completed(new CertificationJourneyCompleted('journey', 42, 94));
        $conductor->credential_issued(new CredentialIssued('journey', 42, 'portfolio', 'credential'));

        $args = ['scenario_id' => 'journey', 'journey_id' => 'journey'];
        self::assertSame([
            [1_000 + ScenarioPlan::BOUNDARY_DELAY, ScenarioPlan::SUBMIT_DIAGNOSTIC, $args, ScenarioCoordinator::GROUP],
            [1_000 + ScenarioPlan::BOUNDARY_DELAY, ScenarioPlan::SUBMIT_CAPSTONE, $args, ScenarioCoordinator::GROUP],
            [1_000 + ScenarioPlan::BOUNDARY_DELAY, ScenarioPlan::RECORD_ATTESTATION, $args, ScenarioCoordinator::GROUP],
            [1_000 + ScenarioPlan::BOUNDARY_DELAY, ScenarioPlan::ACKNOWLEDGE_REGISTRY, $args, ScenarioCoordinator::GROUP],
        ], $queue->single);
    }
}

final class ConductorRecordingQueue implements ScenarioQueue
{
    /** @var list<array{int, string, array<string, string>, string}> */
    public array $single = [];

    public function schedule(int $timestamp, string $hook, array $args, string $group): void
    {
        $this->single[] = [$timestamp, $hook, $args, $group];
    }

    public function schedule_recurring(int $timestamp, int $interval, string $hook, string $group): void
    {
    }

    public function has_scheduled(string $hook, string $group): bool
    {
        return false;
    }

    public function cancel_all(string $hook, string $group): void
    {
    }
}
