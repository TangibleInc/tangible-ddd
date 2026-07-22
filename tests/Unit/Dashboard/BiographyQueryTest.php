<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\Query\BiographyQuery;

final class BiographyQueryTest extends TestCase
{
    public function test_recent_finds_consumer_owned_aggregates_with_search_and_pagination(): void
    {
        $db = new ScriptedDatabase();
        $db->values = [2];
        $db->resultSets = [[[
            'aggregate' => 'tangible_lms.learning_journey',
            'aggregate_id' => 'journey-42',
            'touch_count' => '7',
            'first_version' => '1',
            'last_version' => '7',
            'first_at' => '2026-07-21 23:58:52',
            'last_at' => '2026-07-22 00:08:03',
            'last_op' => 'updated',
        ]]];

        $result = (new BiographyQuery(new FakeDDDConfig(), $db))->recent([
            'search' => 'journey_42%',
            'page' => 2,
            'per_page' => 10,
        ]);

        self::assertTrue($result['available']);
        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['page']);
        self::assertSame(10, $result['per_page']);
        self::assertSame(1, $result['pages']);
        self::assertSame(7, $result['rows'][0]['touch_count']);
        self::assertSame(1, $result['rows'][0]['first_version']);
        self::assertSame(7, $result['rows'][0]['last_version']);
        self::assertStringContainsString('wp_test_touches', $db->prepared[0]['sql']);
        self::assertStringContainsString('GROUP BY aggregate, aggregate_id', $db->prepared[0]['sql']);
        self::assertSame(['%journey\_42\%%', '%journey\_42\%%'], $db->prepared[0]['args']);
        self::assertSame(['%journey\_42\%%', '%journey\_42\%%', 10, 10], $db->prepared[1]['args']);
    }

    public function test_read_returns_versioned_entries_with_recorded_command_fact_and_trace_links(): void
    {
        $db = new ScriptedDatabase();
        $db->resultSets = [[[
            'touch_count' => '1',
            'first_version' => '7',
            'last_version' => '7',
            'first_at' => '2026-07-22 00:08:03',
            'last_at' => '2026-07-22 00:08:03',
        ]], [[
            'id' => '9',
            'aggregate' => 'tangible_lms.learning_journey',
            'aggregate_id' => 'journey-42',
            'op' => 'updated',
            'version' => '7',
            'event_name' => 'certification_record_archived',
            'event_id' => 'event-7',
            'command_id' => 'command-7',
            'correlation_id' => 'corr-7',
            'occurred_at' => '2026-07-22 00:08:03',
            'command_name' => 'Tangible\\LMS\\ArchiveCertificationRecord',
            'command_status' => 'success',
            'source' => 'system',
            'duration_ms' => '12',
            'started_at' => '2026-07-22 00:08:03',
            'event_type' => 'Tangible\\LMS\\CertificationRecordArchived',
            'event_status' => 'completed',
            'processed_at' => '2026-07-22 00:08:33',
        ]]];

        $result = (new BiographyQuery(new FakeDDDConfig(), $db))->read(
            'tangible_lms.learning_journey',
            'journey-42',
        );

        self::assertTrue($result['available']);
        self::assertSame('tangible_lms.learning_journey', $result['aggregate']);
        self::assertSame('journey-42', $result['aggregate_id']);
        self::assertSame([
            'touch_count' => 1,
            'first_version' => 7,
            'last_version' => 7,
            'first_at' => '2026-07-22 00:08:03',
            'last_at' => '2026-07-22 00:08:03',
        ], $result['summary']);
        self::assertSame(1, $result['page']);
        self::assertSame(200, $result['per_page']);
        self::assertSame(1, $result['pages']);
        self::assertSame(9, $result['entries'][0]['id']);
        self::assertSame(7, $result['entries'][0]['version']);
        self::assertSame(12, $result['entries'][0]['duration_ms']);
        self::assertSame('event-7', $result['entries'][0]['event_id']);
        self::assertSame('command-7', $result['entries'][0]['command_id']);
        self::assertSame('corr-7', $result['entries'][0]['correlation_id']);
        // Query 0 = exact summary aggregation; query 1 = the paged ledger.
        self::assertStringContainsString('COUNT(*) touch_count', $db->prepared[0]['sql']);
        self::assertSame(['tangible_lms.learning_journey', 'journey-42'], $db->prepared[0]['args']);
        self::assertStringContainsString('LEFT JOIN `wp_test_command_audit`', $db->prepared[1]['sql']);
        self::assertStringContainsString('LEFT JOIN `wp_test_integration_outbox`', $db->prepared[1]['sql']);
        self::assertStringContainsString('LIMIT %d OFFSET %d', $db->prepared[1]['sql']);
        self::assertSame(['tangible_lms.learning_journey', 'journey-42', 200, 0], $db->prepared[1]['args']);
    }

    public function test_read_pages_the_ledger_while_the_summary_stays_exact(): void
    {
        $db = new ScriptedDatabase();
        $db->resultSets = [[[
            'touch_count' => '450',
            'first_version' => '1',
            'last_version' => '450',
            'first_at' => '2026-07-01 00:00:00',
            'last_at' => '2026-07-22 00:08:03',
        ]], [[
            'id' => '201', 'aggregate' => 'acme.license', 'aggregate_id' => '42',
            'op' => 'updated', 'version' => '201', 'event_name' => 'license_updated',
            'event_id' => 'event-201', 'command_id' => 'command-201',
            'correlation_id' => 'corr-201', 'occurred_at' => '2026-07-10 00:00:00',
        ]]];

        $result = (new BiographyQuery(new FakeDDDConfig(), $db))->read('acme.license', '42', [
            'page' => 2,
            'per_page' => 200,
        ]);

        self::assertSame(450, $result['summary']['touch_count']);
        self::assertSame(2, $result['page']);
        self::assertSame(200, $result['per_page']);
        self::assertSame(3, $result['pages']);
        self::assertSame(201, $result['entries'][0]['version']);
        self::assertSame(['acme.license', '42', 200, 200], $db->prepared[1]['args']);
    }

    public function test_missing_touches_table_is_an_empty_unavailable_read_model(): void
    {
        $db = new ScriptedDatabase();
        $db->missingTables = ['wp_test_touches'];
        $query = new BiographyQuery(new FakeDDDConfig(), $db);

        $recent = $query->recent([]);
        $detail = $query->read('acme.license', '42');

        self::assertFalse($recent['available']);
        self::assertSame([], $recent['rows']);
        self::assertFalse($detail['available']);
        self::assertSame([], $detail['entries']);
        self::assertSame([], $db->prepared);
    }
}
