<?php

namespace TangibleDDD\Tests\Fakes\Conformance;

/**
 * Loading this file is a fatal (parent class doesn't exist) — stands in for
 * consumer infra classes that only parse inside WP. The scanners must never
 * load a file with no integration surface. Nothing here says Integrati*n —
 * the pre-filter is a substring match, so even this docblock avoids the word.
 */
class PoisonPill extends \SomeWordPressOnlyClassThatDoesNotExist {
}
