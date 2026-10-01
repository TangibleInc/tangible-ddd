<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Symfony\DependencyInjection\Compiler\HandlerLocatorPass;
use TangibleDDD\Symfony\DependencyInjection\DddTags;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingListener;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingMarker;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingSelfHandlingCommand;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingSelfHandlingQuery;

final class HandlerLocatorPassTest extends TestCase {

  private function container(array $selfHandling = []): ContainerBuilder {
    $c = new ContainerBuilder();
    $c->setParameter('tangible_ddd.self_handling', $selfHandling + ['classes' => [], 'locate_all' => false]);
    $c->setDefinition('tangible_ddd.middleware.self_executing', new Definition(SelfExecutingCommandMiddleware::class, [new AbstractArgument('locator')]));
    $c->setDefinition(EventsUnitOfWork::class, new Definition(EventsUnitOfWork::class));
    $c->setDefinition(PingListener::class, new Definition(PingListener::class));
    $c->setDefinition('app.marker_impl', new Definition(\stdClass::class));
    $c->setAlias(PingMarker::class, 'app.marker_impl');
    $c->setDefinition(PingFact::class, new Definition(PingFact::class)); // an unrelated, unused app service
    return $c;
  }

  /** @return list<string> */
  private static function locatorKeys(ContainerBuilder $c): array {
    $ref = $c->getDefinition('tangible_ddd.middleware.self_executing')->getArgument(0);
    self::assertInstanceOf(Reference::class, $ref);
    $map = $c->getDefinition((string) $ref)->getArgument(0);
    foreach ($map as $v) {
      self::assertInstanceOf(ServiceClosureArgument::class, $v);
    }
    $keys = array_keys($map);
    sort($keys);
    return $keys;
  }

  public function test_the_locator_holds_only_the_handle_parameter_types_of_tagged_self_handling_classes(): void {
    $c = $this->container();
    $c->setDefinition(PingSelfHandlingCommand::class, (new Definition(PingSelfHandlingCommand::class))->addTag(DddTags::SELF_HANDLING));
    $c->setDefinition(PingSelfHandlingQuery::class, (new Definition(PingSelfHandlingQuery::class))->addTag(DddTags::SELF_HANDLING));

    (new HandlerLocatorPass())->process($c);

    self::assertSame([EventsUnitOfWork::class, PingListener::class, PingMarker::class], self::locatorKeys($c));
  }

  public function test_classes_listed_in_configuration_are_located_without_being_services(): void {
    $c = $this->container(['classes' => [PingSelfHandlingCommand::class]]);

    (new HandlerLocatorPass())->process($c);

    self::assertSame([EventsUnitOfWork::class, PingListener::class], self::locatorKeys($c));
  }

  public function test_locate_all_restores_every_class_named_service(): void {
    $c = $this->container(['locate_all' => true]);

    (new HandlerLocatorPass())->process($c);

    self::assertContains(PingFact::class, self::locatorKeys($c));
  }

  public function test_a_listed_class_that_is_not_self_handling_fails_compilation(): void {
    $c = $this->container(['classes' => [PingListener::class]]);

    $this->expectException(\Symfony\Component\DependencyInjection\Exception\InvalidArgumentException::class);
    (new HandlerLocatorPass())->process($c);
  }
}
