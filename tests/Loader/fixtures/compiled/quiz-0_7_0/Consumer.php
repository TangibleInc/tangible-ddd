<?php
// Stubs of the tangible-quiz-0.7.0.zip classes the kept factories construct (generated;
// see tests/Loader/bin/extract-compiled-container.php).

namespace FxCompiled\Quiz\Consumer\Application\Middleware {
    class DoctrineTransactionMiddleware implements \League\Tactician\Middleware
    {
        public function execute($command, callable $next)
        {
            return $next($command);
        }
    }
}

namespace FxCompiled\Quiz\Consumer\Infra {
    class Config extends \FxCompiled\Support\FixtureConfig
    {
        public const PREFIX = 'fxcc_quiz';
    }
}
