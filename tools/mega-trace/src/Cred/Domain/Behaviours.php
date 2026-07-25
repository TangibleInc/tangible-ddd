<?php

declare(strict_types=1);

namespace Tangible\Cred\MegaTrace\Domain\Behaviours;

use stdClass;
use TangibleDDD\Domain\ValueObjects\Behaviours\BatchableBehaviourConfig;

final class ReviewIssuanceEvidence extends BatchableBehaviourConfig
{
    public const TYPE = 'mega_trace_review_issuance_evidence';

    public function get_behaviour_type(): string
    {
        return self::TYPE;
    }

    public function get_default_batch_size(): int
    {
        return 1;
    }

    public function clone_with_batch(array $batch): static
    {
        return new static($batch);
    }

    protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static
    {
        $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
        return new static(isset($data->batch) ? (array) $data->batch : []);
    }
}

final class PrepareCredentialArtifacts extends BatchableBehaviourConfig
{
    public const TYPE = 'mega_trace_prepare_credential_artifacts';

    public function get_behaviour_type(): string
    {
        return self::TYPE;
    }

    public function get_default_batch_size(): int
    {
        return 1;
    }

    public function clone_with_batch(array $batch): static
    {
        return new static($batch);
    }

    protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static
    {
        $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
        return new static(isset($data->batch) ? (array) $data->batch : []);
    }
}

/**
 * ── The CALM chain (CertificationAuditRoutine) ──────────────────────────
 * Unlike the issuance pair above (zero budget = one item per pass, maximum
 * trace drama), these three run at a real time budget so passes are few and
 * wide and the loom shows cross-segment brackets — the production shape.
 */
final class ValidateCompliance extends BatchableBehaviourConfig
{
    public const TYPE = 'mega_trace_validate_compliance';

    public function get_behaviour_type(): string
    {
        return self::TYPE;
    }

    public function get_default_batch_size(): int
    {
        return 100; // budget paces the pass, not the chunk
    }

    public function clone_with_batch(array $batch): static
    {
        return new static($batch);
    }

    protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static
    {
        $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
        return new static(isset($data->batch) ? (array) $data->batch : []);
    }
}

final class ReconcileLedger extends BatchableBehaviourConfig
{
    public const TYPE = 'mega_trace_reconcile_ledger';

    public function get_behaviour_type(): string
    {
        return self::TYPE;
    }

    public function get_default_batch_size(): int
    {
        return 100;
    }

    public function clone_with_batch(array $batch): static
    {
        return new static($batch);
    }

    protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static
    {
        $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
        return new static(isset($data->batch) ? (array) $data->batch : []);
    }
}

final class NotifyBoards extends BatchableBehaviourConfig
{
    public const TYPE = 'mega_trace_notify_boards';

    public function get_behaviour_type(): string
    {
        return self::TYPE;
    }

    public function get_default_batch_size(): int
    {
        return 100;
    }

    public function clone_with_batch(array $batch): static
    {
        return new static($batch);
    }

    protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static
    {
        $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
        return new static(isset($data->batch) ? (array) $data->batch : []);
    }
}
