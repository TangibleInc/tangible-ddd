<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Config;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Tests\Fakes\Dashboard\ScriptedDatabase;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\ConsumerCatalog;

final class ConsumerCatalogTest extends TestCase
{
    public function test_it_combines_registered_self_and_ghost_consumers(): void
    {
        $db = new ScriptedDatabase('wp_x_');
        $db->columns = [[
            'wp_x_test_command_audit',
            'wp_x_orphan_command_audit',
        ]];
        $handle = new ConsumerHandle(new FakeDDDConfig(), static fn (): object => new \stdClass());

        $catalog = new ConsumerCatalog(
            $db,
            static fn (): array => ['test' => $handle],
            static fn (): Config => new Config('wp_x_'),
            static fn (string $key): ?string => $key === 'test' ? '#123456' : null,
        );

        $all = $catalog->all();

        self::assertSame(['test', 'tangible_ddd', 'orphan'], array_keys($all));
        self::assertFalse($all['test']->ghost);
        self::assertSame('#123456', $all['test']->accent);
        self::assertSame('test', $all['test']->config()?->prefix());
        self::assertSame('tangible_ddd', $all['tangible_ddd']->config()?->prefix());
        self::assertTrue($all['orphan']->ghost);
        self::assertSame('wp_x_orphan_command_audit', $all['orphan']->config()?->table('command_audit'));
        self::assertSame('orphan', $catalog->prefix('orphan'));
        self::assertNull($catalog->get('missing'));
    }

    public function test_consumer_accent_fallback_is_stable_and_rejects_unsafe_overrides(): void
    {
        $resolver = static fn (): FakeDDDConfig => new FakeDDDConfig();
        $first = new \TangibleDDD\WordPress\Admin\Dashboard\ConsumerDefinition('stable', 'Stable', $resolver);
        $again = new \TangibleDDD\WordPress\Admin\Dashboard\ConsumerDefinition('stable', 'Stable', $resolver);
        $unsafe = new \TangibleDDD\WordPress\Admin\Dashboard\ConsumerDefinition(
            'stable',
            'Stable',
            $resolver,
            false,
            'red; background:url(evil)',
        );

        self::assertSame($first->accent, $again->accent);
        self::assertSame($first->accent, $unsafe->accent);
    }

    public function test_live_tangible_consumers_receive_distinct_stable_fallback_accents(): void
    {
        $resolver = static fn (): FakeDDDConfig => new FakeDDDConfig();
        $keys = ['tangible_lms', 'tangible_quiz', 'tgbl_cred', 'tangible_datastream'];
        $accents = array_map(
            static fn (string $key): string => (new \TangibleDDD\WordPress\Admin\Dashboard\ConsumerDefinition(
                $key,
                $key,
                $resolver,
            ))->accent,
            $keys,
        );

        self::assertCount(count($keys), array_unique($accents), implode(', ', $accents));
    }
}
