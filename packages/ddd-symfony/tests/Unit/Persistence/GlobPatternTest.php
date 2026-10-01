<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Symfony\Persistence\GlobPattern;

final class GlobPatternTest extends TestCase {

  /** @return iterable<string, array{string, string}> */
  public static function cases(): iterable {
    yield 'exact' => ['widget_registered', 'widget_registered'];
    yield 'star' => ['*', 'anything'];
    yield 'prefix glob' => ['acme_order_*', 'acme_order_placed'];
    yield 'no match' => ['acme_order_*', 'acme_invoice_sent'];
    yield 'anchored start' => ['order_*', 'acme_order_placed'];
    yield 'question mark' => ['widget_?', 'widget_a'];
    yield 'class' => ['widget_[ab]', 'widget_b'];
    yield 'negated class' => ['widget_[!ab]', 'widget_c'];
    yield 'regex metachar is literal' => ['a.b', 'axb'];
    yield 'plus is literal' => ['a+b', 'a+b'];
  }

  #[DataProvider('cases')]
  public function test_regex_agrees_with_fnmatch(string $glob, string $subject): void {
    $regex = GlobPattern::to_regex($glob);

    self::assertSame(
      fnmatch($glob, $subject),
      preg_match('/' . str_replace('/', '\/', $regex) . '/', $subject) === 1,
      "glob $glob vs $subject (regex $regex)"
    );
  }
}
