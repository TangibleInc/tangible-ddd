<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard\Query;

use TangibleDDD\Application\Tracing\TraceStitcher;
use TangibleDDD\WordPress\Admin\Dashboard\ConsumerCatalog;
use TangibleDDD\WordPress\Admin\Dashboard\Database;

final class UnifiedTraceQuery
{
    public function __construct(
        private readonly ConsumerCatalog $consumers,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function assemble(string $correlationId): array
    {
        $fragments = [];
        foreach ($this->consumers->all() as $consumer) {
            $fragments[] = (new TraceFragmentReader($consumer, $this->db))->read($correlationId);
        }

        $graph = (new TraceStitcher())->stitch($fragments);
        return (new TraceTimelinePresenter())->present($correlationId, $graph);
    }
}
