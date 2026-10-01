<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\AbstractRecursivePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Symfony\Bundle\ConsumerSettings;

/**
 * Wave 5, several consumers: compile-time assignment of the app's services to
 * a consumer, by namespace root (ConsumerRegistry::owner_of semantics: the
 * longest root that contains the class, whole segments only).
 *
 * A service owned by a non-primary consumer, a handler, listener, workflow,
 * repository or any other class in its namespace, that references one of
 * the primary consumer's ddd services (`tangible_ddd.outbox_store`, the
 * ITransactionBoundary alias's target, the command bus, the process runner,
 * the workflow stores, ...) gets that consumer's own service instead
 * (`tangible_ddd.consumer.{name}.*`). Autowiring by port interface therefore
 * does the right thing in every consumer. Nested inline definitions and
 * locators of the service are rewritten too; services outside every root,
 * and the library's own services, are left alone.
 *
 * Runs before removal, when autowiring and alias resolution are done. With
 * one consumer it does nothing.
 *
 * @internal
 */
final class ConsumerAssignmentPass extends AbstractRecursivePass {

  /** @var list<array{root: string, name: string, map: array<string, string>}> longest root first */
  private array $owners = [];

  /** @var array<string, string>|null the map of the service being processed */
  private ?array $map = null;

  public function process(ContainerBuilder $container): void {
    if (!$container->hasParameter('tangible_ddd.consumers')) {
      return;
    }
    /** @var list<array<string, mixed>> $consumers */
    $consumers = $container->getParameter('tangible_ddd.consumers');
    if (count($consumers) < 2) {
      return;
    }

    $primary = $consumers[0];
    $owners = [];
    foreach ($consumers as $c) {
      $map = [];
      if (!$c['primary']) {
        $own = 'tangible_ddd.consumer.' . $c['name'] . '.';
        foreach (array_keys($container->getDefinitions()) as $id) {
          if (str_starts_with($id, $own)) {
            $map[ConsumerSettings::id($primary, substr($id, strlen($own)))] = $id;
          }
        }
      }
      $owners[] = ['root' => $c['namespace_root'], 'name' => $c['name'], 'map' => $map];
    }
    usort($owners, static fn (array $a, array $b) => strlen($b['root']) <=> strlen($a['root']));
    $this->owners = $owners;

    try {
      parent::process($container);
    } finally {
      $this->owners = [];
      $this->map = null;
    }
  }

  protected function processValue(mixed $value, bool $isRoot = false): mixed {
    if ($isRoot && !$value instanceof Definition) {
      return parent::processValue($value, $isRoot); // the map of definitions: each comes back here as a root
    }
    if ($isRoot) {
      $this->map = $this->map_for($value);
    }
    if ($this->map === null || $this->map === []) {
      return $value; // not owned by a non-primary consumer: nothing to rewrite in this definition
    }
    if ($value instanceof Reference && isset($this->map[(string) $value])) {
      return new Reference($this->map[(string) $value], $value->getInvalidBehavior());
    }
    return parent::processValue($value, $isRoot);
  }

  /** @return array<string, string>|null */
  private function map_for(Definition $definition): ?array {
    if ($this->currentId !== null && str_starts_with($this->currentId, 'tangible_ddd.')) {
      return null; // the library's services are wired per consumer already
    }
    $class = $definition->getClass();
    if (!is_string($class) || $class === '') {
      return null;
    }
    $class = ltrim($class, '\\');
    foreach ($this->owners as $owner) {
      if ($class === $owner['root'] || str_starts_with($class, $owner['root'] . '\\')) {
        return $owner['map'];
      }
    }
    return null;
  }
}
