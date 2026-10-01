<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\FactRef;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Ids\NameBasedUuid;

/** Wave-2 follow-ups D13 / CR-6: Correlation::current_fact() and Uuid::v5(). */
final class CurrentFactAndUuidTest extends TestCase {

  protected function tearDown(): void {
    Correlation::reset();
  }

  public function test_current_fact_is_null_in_a_flat_context(): void {
    self::assertNull(Correlation::current_fact());
  }

  public function test_current_fact_names_the_fact_being_delivered(): void {
    $ref = Correlation::within(
      (new TraceContext('corr-9'))->for_fact('evt-1', 'App\\Events\\OrderPlaced'),
      static fn () => Correlation::current_fact()
    );

    self::assertEquals(new FactRef('evt-1', 'App\\Events\\OrderPlaced', 'corr-9'), $ref);
  }

  public function test_current_fact_is_null_inside_an_act_or_a_trajectory(): void {
    $ctx = new TraceContext('corr-9');

    self::assertNull(Correlation::within($ctx->for_act('cmd-1', 'X'), static fn () => Correlation::current_fact()));
    self::assertNull(Correlation::within($ctx->for_trajectory('7', 'P'), static fn () => Correlation::current_fact()));
  }

  public function test_current_fact_without_a_label_has_an_empty_class(): void {
    $ref = Correlation::within((new TraceContext('c'))->for_fact('evt-2'), static fn () => Correlation::current_fact());

    self::assertSame('', $ref?->eventClass);
  }

  public function test_current_fact_does_not_mint_an_ambient_story(): void {
    Correlation::current_fact();
    self::assertNull(Correlation::peek());
  }

  public function test_uuid_v5_delegates_to_the_name_based_mint(): void {
    $ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    self::assertSame('2ed6657d-e927-568b-95e1-2665a8aea6a2', Uuid::v5($ns, 'www.example.com'));
    self::assertSame(NameBasedUuid::v5($ns, 'abc'), Uuid::v5($ns, 'abc'));
  }
}
