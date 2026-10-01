<?php

declare(strict_types=1);

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

/** The in-memory IBehaviourTypes every host can use (W2). */
final class BehaviourTypes implements IBehaviourTypes {

  /** @param array<string, class-string<BaseBehaviourConfig>> $map */
  public function __construct(private array $map = []) {}

  public function register(string $type, string $class): void {
    $this->map[$type] = $class;
  }

  public function find(string $type): ?string {
    return $this->map[$type] ?? null;
  }
}
