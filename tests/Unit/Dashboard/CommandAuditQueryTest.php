<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Query\CommandAuditQuery;

final class CommandAuditQueryTest extends TestCase
{
    public function test_it_preserves_filters_pagination_and_json_normalization(): void
    {
        $db = new ScriptedDatabase();
        $db->values = [2];
        $db->resultSets = [[[
            'command_id' => 'cmd-1',
            'correlation_id' => 'corr-1',
            'command_name' => 'App\\DoThing',
            'status' => 'error',
            'source' => 'cli',
            'source_id' => '7',
            'causation_id' => null,
            'causation_type' => null,
            'duration_ms' => '31',
            'peak_memory_bytes' => '4096',
            'started_at' => '2026-07-19 09:00:00',
            'ended_at' => '2026-07-19 09:00:01',
            'parameters' => '{"id":7}',
            'events' => '[{"name":"ThingDone"}]',
            'error' => '{"message":"nope"}',
        ]]];

        $result = (new CommandAuditQuery(new FakeDDDConfig(), $db))->run([
            'status' => 'error',
            'source' => 'cli',
            'search' => 'corr-1',
            'from' => '2026-07-18',
            'to' => '2026-07-19',
            'orderby' => 'duration_ms',
            'order' => 'ASC',
            'page' => 2,
            'per_page' => 1,
        ]);

        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['page']);
        self::assertSame(2, $result['pages']);
        self::assertSame(31, $result['rows'][0]['duration_ms']);
        self::assertSame(['id' => 7], $result['rows'][0]['parameters']);
        self::assertSame([['name' => 'ThingDone']], $result['rows'][0]['events']);
        self::assertSame(['message' => 'nope'], $result['rows'][0]['error']);

        self::assertCount(2, $db->prepared);
        self::assertSame(
            ['error', 'cli', '%corr-1%', '%corr-1%', '%corr-1%', '2026-07-18 00:00:00', '2026-07-19 23:59:59'],
            $db->prepared[0]['args']
        );
        self::assertSame([1, 1], array_slice($db->prepared[1]['args'], -2));
        self::assertStringContainsString('ORDER BY duration_ms ASC', $db->prepared[1]['sql']);
    }
}
