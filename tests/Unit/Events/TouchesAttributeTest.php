<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;
use TangibleDDD\Domain\Events\TouchesNonAggregate;
use TangibleDDD\Tests\Fakes\Acme\Domain\StateLicense;

/**
 * #[Touches] — the declared write-set's grammar (spec appendix 9).
 * The ctor guard is the definitive enforcement layer: attributes are lazy,
 * so it fires wherever newInstance() happens — loudly in the conformance
 * scan, tolerated by the never-throws harvest.
 */
class TouchesAttributeTest extends TestCase {

  public function test_accepts_an_aggregate_class(): void {
    $touches = new Touches(Op::Created, StateLicense::class, id: 'license_id');

    $this->assertSame(StateLicense::class, $touches->aggregate);
    $this->assertSame(Op::Created, $touches->op);
    $this->assertSame('license_id', $touches->id);
  }

  public function test_throws_on_a_non_aggregate(): void {
    try {
      new Touches(Op::Updated, \stdClass::class);
      $this->fail('a non-Aggregate reference must throw');
    } catch (TouchesNonAggregate $e) {
      $this->assertStringContainsString('stdClass', $e->getMessage(), 'names the offending class');
    }
  }

  public function test_op_backing_values_are_the_at_rest_dialect(): void {
    // Picked once; columns outlive fashions.
    $this->assertSame('created', Op::Created->value);
    $this->assertSame('updated', Op::Updated->value);
    $this->assertSame('deleted', Op::Deleted->value);
  }

  public function test_canonical_name_defaults_to_snake_cased_basename(): void {
    $this->assertSame('state_license', StateLicense::canonical_name());
  }
}
