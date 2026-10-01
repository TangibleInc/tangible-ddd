<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The configured ORM EntityManager (`tangible_ddd.transaction.entity_manager`)
 * as the transaction boundary sees it (L6): flush() before COMMIT, reset()
 * after every rollback.
 *
 * reset() makes sure nothing of a rolled-back act survives in the ORM:
 *
 * - an open manager is clear()ed, so the changes the failed act scheduled
 *   (persist, changed managed entities, removals) are never flushed by a
 *   later act on the same manager (a worker, a second command in a request);
 * - a closed manager (Doctrine closes it when flush() throws) is reset
 *   through the ManagerRegistry (`doctrine`), so later acts and the
 *   application get a working manager. DoctrineBundle's managers are lazy
 *   services: resetManager() re-initialises the instance that handlers
 *   already hold.
 *
 * clear() detaches every entity, also ones the caller loaded before the act;
 * after a failed act they are stale anyway. In Savepoint mode an outer
 * transaction's unflushed ORM changes are cleared as well.
 *
 * No hard dependency on doctrine/orm or doctrine/persistence: the manager is
 * any object with flush() (clear() / isOpen() are used when present), and
 * the registry any object with getManagerNames() / getManager() /
 * resetManager(). Without a registry, a closed manager cannot be replaced;
 * reset() then logs an error and later acts fail on the closed manager.
 *
 * flush() always flushes the registry's current manager for the configured
 * name, so a manager replaced by resetManager() is never stale here.
 */
final class EntityManagerSession {

  private readonly LoggerInterface $logger;

  /** null = not resolved yet; false = not a manager of the registry. */
  private string|false|null $managerName = null;

  public function __construct(
    private readonly object $manager,
    private readonly ?object $registry = null,
    private readonly ?string $serviceId = null,
    ?LoggerInterface $logger = null,
  ) {
    if (!method_exists($manager, 'flush')) {
      throw new \InvalidArgumentException(sprintf(
        'tangible_ddd.transaction.entity_manager: %s has no flush() method.', get_class($manager)
      ));
    }
    $this->logger = $logger ?? new NullLogger();
  }

  public function flush(): void {
    $this->current()->flush();
  }

  public function reset(): void {
    $manager = $this->current();
    if (method_exists($manager, 'isOpen') && !$manager->isOpen()) {
      $name = $this->name();
      if ($name === false) {
        $this->logger->error(sprintf(
          '[ddd tx] the EntityManager %s is closed after a rollback and no ManagerRegistry manages it; later acts will fail on it.',
          $this->serviceId ?? get_class($manager)
        ));
        return;
      }
      $this->registry->resetManager($name);
      return;
    }
    if (method_exists($manager, 'clear')) {
      $manager->clear();
    }
  }

  private function current(): object {
    $name = $this->name();
    return $name === false ? $this->manager : $this->registry->getManager($name);
  }

  /** The registry's name for the configured manager, or false. */
  private function name(): string|false {
    if ($this->managerName !== null) {
      return $this->managerName;
    }
    $registry = $this->registry;
    if ($registry === null || !method_exists($registry, 'getManagerNames') || !method_exists($registry, 'resetManager')) {
      return $this->managerName = false;
    }
    $names = (array) $registry->getManagerNames();
    foreach ($names as $name => $id) {
      if ($this->serviceId !== null && $id === $this->serviceId) {
        return $this->managerName = (string) $name;
      }
    }
    // Configured through an alias (e.g. Doctrine\ORM\EntityManagerInterface): match the instance.
    foreach (array_keys($names) as $name) {
      if ($registry->getManager((string) $name) === $this->manager) {
        return $this->managerName = (string) $name;
      }
    }
    return $this->managerName = false;
  }
}
