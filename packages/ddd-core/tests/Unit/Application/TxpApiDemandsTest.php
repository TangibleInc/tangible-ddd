<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use League\Tactician\CommandBus;
use League\Tactician\Handler\CommandHandlerMiddleware;
use League\Tactician\Handler\Mapping\MapByStaticList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\CommandHandlers\IReturningCommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Application\EventHandlers\IntegrationTranslator;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Domain\Exceptions\BusinessConstraintException;
use TangibleDDD\Domain\Exceptions\NotPermittedException;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Testing\InMemoryTransactionBoundary;

/**
 * TXP tenancy-reference demands L1, L3, L7 (wave3-notes "TXP demands").
 */
final class TxpApiDemandsTest extends TestCase {

  protected function setUp(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
  }

  // ── L1 ────────────────────────────────────────────────────────────────────

  public function test_l1_a_returning_plain_handler_returns_its_value_through_the_frozen_middleware_order(): void {
    $command = new class implements ICommand, ITransactionalCommand {
      public function send(): mixed {
        throw new \LogicException('not used');
      }
    };
    $handler = new class implements IReturningCommandHandler {
      public function handle(ICommand $command): mixed {
        return ['team_id' => 'b0c4'];
      }
    };

    $events = new EventsUnitOfWork();
    $container = new class ($handler) implements ContainerInterface {
      public function __construct(private readonly object $handler) {}
      public function get(string $id): object { return $this->handler; }
      public function has(string $id): bool { return true; }
    };
    $bus = new CommandBus(
      new CorrelationMiddleware(new AcmeConfig(), $events, new Redactor()),
      new TransactionalCommandMiddleware(new InMemoryTransactionBoundary()),
      new DomainEventsPublishMiddleware($events, new EventRouter(new OrderedListenerDispatcher(), new class implements IIntegrationEventBus { public function publish(IIntegrationEvent $event): void {} })),
      new CommandHandlerMiddleware($container, new MapByStaticList([get_class($command) => [get_class($handler), 'handle']])),
    );

    self::assertSame(['team_id' => 'b0c4'], $bus->handle($command));
  }

  public function test_l1_the_returning_handler_is_a_sibling_of_the_void_handler_not_a_subtype(): void {
    self::assertFalse(is_a(IReturningCommandHandler::class, ICommandHandler::class, true));
    self::assertSame('mixed', (string) (new \ReflectionMethod(IReturningCommandHandler::class, 'handle'))->getReturnType());
    self::assertSame('void', (string) (new \ReflectionMethod(ICommandHandler::class, 'handle'))->getReturnType());
  }

  public function test_l1_a_self_handling_command_may_not_inject_a_returning_handler_either(): void {
    $command = new class extends \TangibleDDD\Application\Commands\SelfHandlingCommand {
      protected function handle(IReturningCommandHandler $handler): mixed {
        return $handler->handle($this);
      }
    };
    $container = new class implements ContainerInterface {
      public function get(string $id): mixed { throw new \LogicException('not consulted'); }
      public function has(string $id): bool { return false; }
    };

    $this->expectException(\TangibleDDD\Application\Exceptions\SelfHandlingCommandWrapsHandler::class);
    (new \TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware($container))->execute($command, static fn () => null);
  }

  // ── L3 ────────────────────────────────────────────────────────────────────

  public function test_l3_the_translator_hooks_are_typed_class_string_fact_or_marker(): void {
    foreach (['get_event_class', 'event_class'] as $method) {
      $doc = (string) (new \ReflectionMethod(IntegrationTranslator::class, $method))->getDocComment();
      self::assertMatchesRegularExpression('/@return class-string\s/', $doc, $method);
      self::assertStringNotContainsString('class-string<', $doc, $method);
      self::assertStringContainsString('marker interface', $doc, $method);
    }
  }

  // ── L7 ────────────────────────────────────────────────────────────────────

  public function test_l7_not_permitted_is_a_business_constraint(): void {
    $e = new NotPermittedException('only owners may remove members');

    self::assertInstanceOf(BusinessConstraintException::class, $e);
    self::assertSame('only owners may remove members', $e->getMessage());
    try {
      throw $e;
    } catch (BusinessConstraintException $caught) {
      self::assertSame($e, $caught, 'existing catches of the parent still match');
    }
  }
}
