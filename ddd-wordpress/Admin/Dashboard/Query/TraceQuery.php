<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Application\Tracing\TraceStitcher;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\WordPress\Admin\Dashboard\ConsumerDefinition;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

/** Single-consumer compatibility facade over the V2 trace projection. */
final class TraceQuery
{
    public function __construct(
        private readonly IDDDConfig $config,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function assemble(string $correlationId): array
    {
        $consumer = new ConsumerDefinition(
            $this->config->prefix(),
            $this->config->prefix(),
            fn (): IDDDConfig => $this->config,
        );
        $fragment = (new TraceFragmentReader($consumer, $this->db))->read($correlationId);
        $graph = (new TraceStitcher())->stitch([$fragment]);

        return (new TraceTimelinePresenter())->present($correlationId, $graph);
    }
}
