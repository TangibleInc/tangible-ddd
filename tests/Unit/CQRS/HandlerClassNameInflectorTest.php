<?php

namespace TangibleDDD\Tests\Unit\CQRS;

use League\Tactician\Handler\Mapping\ClassName\ClassNameInflector;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\CQRS\HandlerClassNameInflector;

/**
 * The bus naming convention, shipped ONCE (0.2.5c). Every consumer's
 * stamped copy was byte-identical generic string surgery — no consumer
 * knowledge anywhere. (The framework's previous copy was deleted in 0.2.4
 * for a broken League import; this is the corrected canonical.)
 */
class HandlerClassNameInflectorTest extends TestCase {

  private HandlerClassNameInflector $inflector;

  protected function setUp(): void {
    $this->inflector = new HandlerClassNameInflector();
  }

  public function test_implements_tacticians_contract(): void {
    $this->assertInstanceOf(ClassNameInflector::class, $this->inflector);
  }

  public function test_commands_map_to_command_handlers(): void {
    $this->assertSame(
      'Tangible\\Cred\\Application\\CommandHandlers\\IssueEarningHandler',
      $this->inflector->getClassName('Tangible\\Cred\\Application\\Commands\\IssueEarningCommand'),
    );
  }

  public function test_queries_map_to_query_handlers(): void {
    $this->assertSame(
      'Acme\\X\\Application\\QueryHandlers\\GetUserEarningsHandler',
      $this->inflector->getClassName('Acme\\X\\Application\\Queries\\GetUserEarningsQuery'),
    );
  }

  public function test_neither_command_nor_query_throws(): void {
    $this->expectException(\LogicException::class);

    $this->inflector->getClassName('Acme\\X\\Domain\\Services\\SomethingService');
  }
}
