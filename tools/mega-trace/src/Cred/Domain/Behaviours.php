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
