<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;

/**
 * Resolves a claimed fact's class from the sf outbox row (`event_class`,
 * written by the sf publisher), falling back to an integration_action map
 * over the fact classes the container knows at compile time (every concrete
 * class a listener or process subscribes to, plus `tangible_ddd.facts`).
 *
 * The fallback covers rows written without a class (a core publisher before
 * CR sf-1); it cannot know a fact that only a marker interface subscribes to.
 */
final class OutboxFactClassResolver implements IFactClassResolver {

  /** @var array<string, string>|null integration_action → class, built on first use */
  private ?array $byAction = null;

  /** @param list<class-string> $fact_classes */
  public function __construct(
    private readonly DbalPostgresOutboxStore $store,
    private readonly array $fact_classes = [],
  ) {}

  public function resolve(Claim $claim): ?string {
    return $this->store->event_class_of($claim->event_id)
      ?? $this->byAction()[$claim->record->integration_action]
      ?? null;
  }

  /** @return array<string, string> */
  private function byAction(): array {
    if ($this->byAction !== null) {
      return $this->byAction;
    }
    $map = [];
    foreach ($this->fact_classes as $class) {
      if (!is_a($class, IIntegrationEvent::class, true) || (new \ReflectionClass($class))->isAbstract()) {
        continue;
      }
      try {
        $map[$class::integration_action()] ??= $class;
      } catch (\Throwable) {
        // an unowned class (no registered consumer) cannot be published either
      }
    }
    return $this->byAction = $map;
  }
}
