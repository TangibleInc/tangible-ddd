<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\WordPress\Admin\Dashboard\ConsumerCatalog;
use TangibleDDD\WordPress\Admin\Dashboard\Query\UnifiedTraceQuery;

final class UnifiedTraceQueryTest extends TestCase
{
    public function test_it_reads_every_registered_consumer_and_returns_one_trace(): void
    {
        $GLOBALS['wpdb'] = new \wpdb();
        $db = new ScriptedDatabase();
        $db->columns = [[]];
        $lms = new DDDConfig('lms', 'Tangible\\LMS', 'test');
        $cred = new DDDConfig('cred', 'Tangible\\Cred', 'test');
        $self = new DDDConfig('tangible_ddd', 'TangibleDDD', 'test');
        $catalog = new ConsumerCatalog(
            $db,
            static fn (): array => [
                'lms' => new ConsumerHandle($lms, static fn (): object => new \stdClass(), 'Learning'),
                'cred' => new ConsumerHandle($cred, static fn (): object => new \stdClass(), 'Credentials'),
            ],
            static fn (): DDDConfig => $self,
        );
        $catalog->all();
        $db->resultSets = [
            [$this->command('cmd-lms', 'Lms\\CompleteCourse', null, null, '2026-07-22 10:00:00')],
            [$this->event('evt-completed', 'Lms\\CourseCompleted', 'cmd-lms', '2026-07-22 10:00:01')],
            [],
            [],
            [],
            [$this->command('cmd-cred', 'Cred\\IssueCredential', 'evt-completed', 'integration_event', '2026-07-22 10:00:02')],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
        ];

        $trace = (new UnifiedTraceQuery($catalog, $db))->assemble('corr-mega');

        self::assertSame(2, $trace['span_count']);
        // The fact docks on its raising act as a port; the cross-consumer
        // subscriber re-points to that act and keeps the handoff flag.
        self::assertSame('lms:e:evt-completed', $trace['nodes'][0]['ports'][0]['uid']);
        self::assertSame('lms:c:cmd-lms', $trace['nodes'][1]['parent']);
        self::assertTrue($trace['nodes'][1]['cross_consumer']);
        self::assertSame(['lms', 'cred'], array_keys($trace['participants']));
        self::assertSame('Learning', $trace['participants']['lms']['label']);
        self::assertCount(16, $db->prepared);
    }

    /** @return array<string, mixed> */
    private function command(
        string $id,
        string $name,
        ?string $causeId,
        ?string $causeType,
        string $startedAt,
    ): array {
        return [
            'command_id' => $id, 'correlation_id' => 'corr-mega', 'command_name' => $name,
            'status' => 'success', 'source' => 'system', 'source_id' => null,
            'causation_id' => $causeId, 'causation_type' => $causeType,
            'duration_ms' => '10', 'peak_memory_bytes' => '1000', 'started_at' => $startedAt,
            'parameters' => '{}', 'events' => '[]', 'error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $name, string $commandId, string $createdAt): array
    {
        return [
            'event_id' => $id, 'event_type' => $name, 'status' => 'completed',
            'command_id' => $commandId, 'sequence' => '1', 'attempts' => '1',
            'created_at' => $createdAt,
        ];
    }
}
