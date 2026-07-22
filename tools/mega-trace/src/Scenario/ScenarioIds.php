<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

final class ScenarioIds
{
    public static function learner(string $journey_id): int
    {
        return 10_000 + (self::number($journey_id . ':learner') % 80_000);
    }

    public static function course(string $journey_id): int
    {
        return 1_000 + (self::number($journey_id . ':course') % 8_000);
    }

    public static function reference(string $journey_id): int
    {
        return 1 + (self::number($journey_id . ':reference') % 2_000_000_000);
    }

    public static function attempt(string $journey_id): string
    {
        return 'attempt-' . substr(hash('sha256', $journey_id . ':attempt'), 0, 12);
    }

    public static function portfolio(string $journey_id): string
    {
        return 'portfolio-' . substr(hash('sha256', $journey_id . ':portfolio'), 0, 12);
    }

    public static function credential(string $journey_id): string
    {
        return 'credential-' . substr(hash('sha256', $journey_id . ':credential'), 0, 12);
    }

    public static function stream(string $journey_id): string
    {
        return 'stream-' . substr(hash('sha256', $journey_id . ':stream'), 0, 12);
    }

    public static function delivery(string $journey_id): string
    {
        return 'delivery-' . substr(hash('sha256', $journey_id . ':delivery'), 0, 12);
    }

    public static function receipt(string $journey_id): string
    {
        return 'receipt-' . substr(hash('sha256', $journey_id . ':receipt'), 0, 12);
    }

    private static function number(string $seed): int
    {
        return (int) sprintf('%u', crc32($seed));
    }
}
