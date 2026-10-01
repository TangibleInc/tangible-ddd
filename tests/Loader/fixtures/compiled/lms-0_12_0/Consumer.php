<?php
// Stubs of the tangible-lms-0.12.0.zip classes the kept factories construct (generated;
// see tests/Loader/bin/extract-compiled-container.php).

namespace FxCompiled\Lms\Consumer\Application\Middleware {
    class DoctrineTransactionMiddleware implements \League\Tactician\Middleware
    {
        public function execute($command, callable $next)
        {
            return $next($command);
        }
    }
}

namespace FxCompiled\Lms\Consumer\Infra {
    class Config extends \FxCompiled\Support\FixtureConfig
    {
        public const PREFIX = 'fxcc_lms';
    }
}

namespace FxCompiled\Lms\Consumer\Infrastructure\Persistence\Doctrine {
    class OutboxRepository extends \TangibleDDD\Infra\Persistence\OutboxRepository
    {
        public function set_doctrine_plugin_slug(mixed ...$args): void
        {
        }
    }
}
