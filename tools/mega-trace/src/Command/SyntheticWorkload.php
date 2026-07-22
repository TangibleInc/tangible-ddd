<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Command;

use InvalidArgumentException;

final class SyntheticWorkload
{
    public const MAX_MILLISECONDS = 1_200;

    public static function microseconds(int $milliseconds): int
    {
        if ($milliseconds < 0 || $milliseconds > self::MAX_MILLISECONDS) {
            throw new InvalidArgumentException('Synthetic work must be between 0ms and 1200ms.');
        }

        return $milliseconds * 1_000;
    }

    public static function spend(int $milliseconds): void
    {
        $microseconds = self::microseconds($milliseconds);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    public static function routine_item_ms(string $item): int
    {
        return match ($item) {
            'identity' => 120,
            'assessment' => 260,
            'completion' => 390,
            'certificate' => 520,
            'transcript' => 650,
            'badge' => 180,
            default => 0,
        };
    }
}
