<?php

namespace TangibleDDD\Tests\Fakes\Acme\Application;

use TangibleDDD\Application\Commands\SelfHandlingCommand;

/**
 * A CONSUMER self-handling command: lives under the Acme namespace root, so
 * container()/send() must resolve through ConsumerRegistry::owner_of() to
 * ACME's container and bus — never the framework's self-consumer container
 * (where AcmeService does not exist).
 */
class AcmeDoThingCommand extends SelfHandlingCommand {

  public ?AcmeService $got = null;

  protected function handle(AcmeService $svc): void {
    $this->got = $svc;
  }
}
