<?php

namespace TangibleDDD\Tests\Fakes\Acme\Application;

use TangibleDDD\Application\Queries\SelfHandlingQuery;

/**
 * A CONSUMER self-handling query: lives under the Acme namespace root, so
 * container()/send() must resolve through ConsumerRegistry::owner_of() to
 * ACME's container and query bus — never the framework's. Its handle()
 * RETURNS the read result (no receipt rule for queries).
 */
class AcmeFindThingQuery extends SelfHandlingQuery {

  protected function handle(AcmeService $svc): array {
    return ['found_with' => $svc];
  }
}
