<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tangible\Cred\MegaTrace\Application\Commands\QueueCredentialNotification;
use Tangible\Cred\MegaTrace\Application\Commands\VerifyCredentialEvidence;
use Tangible\Datastream\MegaTrace\Application\Commands\CommitRegistryDelivery;
use Tangible\Datastream\MegaTrace\Application\Commands\PackageCredentialEvidence;
use Tangible\LMS\MegaTrace\Application\Commands\LaunchCertificationJourney;
use Tangible\LMS\MegaTrace\Application\Commands\PersonalizeLearningPath;
use Tangible\Quiz\MegaTrace\Application\Commands\AnalyzeDiagnosticSignals;
use TangibleDDD\MegaTrace\Command\SyntheticWorkload;

final class SyntheticWorkloadTest extends TestCase
{
    public function test_selected_commands_declare_the_fixed_workload_profile(): void
    {
        $commands = [
            [new PersonalizeLearningPath('journey', 42, 6), 180],
            [new AnalyzeDiagnosticSignals('journey', 'attempt', 'standard'), 460],
            [new VerifyCredentialEvidence('journey', 'portfolio'), 1_150],
            [new PackageCredentialEvidence('journey', 'stream', 'delivery', 'credential'), 820],
            [new QueueCredentialNotification('journey', 'credential'), 140],
            [new CommitRegistryDelivery('journey', 'portfolio', 'credential', 'delivery', 'receipt'), 360],
        ];

        foreach ($commands as [$command, $milliseconds]) {
            self::assertTrue(
                method_exists($command, 'synthetic_workload_ms'),
                $command::class . ' must expose its measured fixture workload',
            );
            self::assertSame($milliseconds, $command->synthetic_workload_ms());
        }
    }

    public function test_unselected_commands_retain_their_natural_runtime(): void
    {
        $command = new LaunchCertificationJourney('journey', 42, 7);

        self::assertTrue(method_exists($command, 'synthetic_workload_ms'));
        self::assertSame(0, $command->synthetic_workload_ms());
    }

    public function test_routine_items_have_a_deterministic_bounded_profile(): void
    {
        self::assertTrue(class_exists(SyntheticWorkload::class));
        $profile = [
            'identity' => 120,
            'assessment' => 260,
            'completion' => 390,
            'certificate' => 520,
            'transcript' => 650,
            'badge' => 180,
        ];
        foreach ($profile as $item => $milliseconds) {
            self::assertSame($milliseconds, SyntheticWorkload::routine_item_ms($item));
        }
        self::assertSame(0, SyntheticWorkload::routine_item_ms('unknown'));
    }

    public function test_workload_conversion_enforces_the_fixture_ceiling(): void
    {
        self::assertTrue(class_exists(SyntheticWorkload::class));
        self::assertSame(1_150_000, SyntheticWorkload::microseconds(1_150));

        $this->expectException(InvalidArgumentException::class);
        SyntheticWorkload::microseconds(1_201);
    }
}
