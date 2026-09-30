<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Base of every abstract scenario case. A host plugs in by extending a
 * scenario case with a concrete class that carries `#[Group('<host>')]` and
 * returns its fixture from createFixture(); nothing else is host-specific.
 *
 *   #[Group('sf')]
 *   final class SfRelayScenariosTest extends RelayScenarios {
 *     protected function createFixture(): HostFixture { return new SfHostFixture(...); }
 *   }
 *
 * A scenario that a host cannot express yet is skipped with the id of the
 * API change request that would unblock it (skipForChangeRequest()).
 */
abstract class ConformanceTestCase extends TestCase {

  protected HostFixture $host;

  abstract protected function createFixture(): HostFixture;

  protected function setUp(): void {
    parent::setUp();
    $this->host = $this->createFixture();
    $this->host->setUp(new ScenarioContext(static::class, $this->name(), ScenarioId::of(new \ReflectionMethod($this, $this->name()))));
  }

  protected function tearDown(): void {
    $this->host->tearDown();
    parent::tearDown();
  }

  /** The wire form a relay would hand the delivery runner for $fact under $eventId. */
  protected static function wrap(IIntegrationEvent $fact, string $eventId, ?string $correlationId = null, int $sequence = 1): array {
    return IntegrationEnvelope::wrap($fact->integration_payload(), $correlationId ?? 'corr-' . $eventId, $sequence, $eventId);
  }

  /**
   * Publish $fact the way application code does: a transactional command
   * whose handler records it, committed through the host bus into the
   * host outbox. Returns the outbox event id.
   */
  protected function publishFact(DomainEvent&IIntegrationEvent $fact): string {
    $bus = $this->host->commandBus([CreateWidget::class => function () use ($fact): void {
      $this->host->events()->record($fact);
    }]);
    $bus->handle(new CreateWidget('publish-' . spl_object_id($fact)));

    return PublishedFacts::id_of($fact) ?? throw new \LogicException('The host bus did not publish the fact');
  }

  /** What $fn threw, or null. */
  protected static function catchThrowable(callable $fn): ?\Throwable {
    try {
      $fn();
    } catch (\Throwable $e) {
      return $e;
    }
    return null;
  }

  protected function skipForChangeRequest(string $requestId, string $why): never {
    self::markTestSkipped("blocked on $requestId: $why");
  }
}
