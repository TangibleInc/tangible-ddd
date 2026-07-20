<?php

namespace TangibleDDD\Tests\Fakes\Acme\Application;

/**
 * A service that exists ONLY in the fake Acme consumer's container — the
 * routing tests prove a self-handling command/query under the Acme namespace
 * gets THIS resolved from Acme's container, never the framework's.
 */
class AcmeService {
}
