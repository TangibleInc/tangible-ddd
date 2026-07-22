<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

final class ScenarioPlan
{
    public const BOUNDARY_DELAY = 25;
    public const SUBMIT_DIAGNOSTIC = 'tddd_mega_trace_submit_diagnostic';
    public const SUBMIT_CAPSTONE = 'tddd_mega_trace_submit_capstone';
    public const RECORD_ATTESTATION = 'tddd_mega_trace_record_attestation';
    public const ACKNOWLEDGE_REGISTRY = 'tddd_mega_trace_acknowledge_registry';

    /** @return list<string> */
    public static function boundary_hooks(): array
    {
        return [
            self::SUBMIT_DIAGNOSTIC,
            self::SUBMIT_CAPSTONE,
            self::RECORD_ATTESTATION,
            self::ACKNOWLEDGE_REGISTRY,
        ];
    }
}
