<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Queries\IQuery;

/**
 * The PSR-11 container DurableRuntime hands to ConsumerRegistry, the
 * self-executing middleware, the handler middleware and the
 * SubscriptionRegistrar. Lookup order:
 *
 *   1. the runtime's own services (CommandBus, the query bus,
 *      EventsUnitOfWork, ProcessRunner, IHostConnection, the boundary, the
 *      clock, the consumer identity, ...);
 *   2. the host's `$handlers`: either its own ContainerInterface, or the
 *      array form `class-string => object|callable`. In the array form an
 *      ICommand / IQuery key maps that message to its handler (see
 *      handlerFor()); any other key is a service: a \Closure (or other
 *      non-object callable) is a lazy factory called once with this
 *      container, any other object is the instance.
 *
 * @internal
 */
final class RuntimeContainer implements ContainerInterface {

  /** @var array<string, object> */
  private array $services = [];

  /** @var array<string, callable|object> message class => handler */
  private array $handlers = [];

  /** @var array<string, callable|object> service id => factory or instance */
  private array $entries = [];

  private ?ContainerInterface $host = null;

  /** @param ContainerInterface|array<string, callable|object> $handlers */
  public function __construct(ContainerInterface|array $handlers) {
    if ($handlers instanceof ContainerInterface) {
      $this->host = $handlers;
      return;
    }
    foreach ($handlers as $id => $entry) {
      if (!is_string($id) || $id === '') {
        throw new \InvalidArgumentException('DurableRuntime handlers must be keyed by class-string');
      }
      if (!is_object($entry) && !is_callable($entry)) {
        throw new \InvalidArgumentException("The handler entry for $id must be an object or a callable");
      }
      if (is_a($id, ICommand::class, true) || is_a($id, IQuery::class, true)) {
        $this->handlers[$id] = $entry;
      } else {
        $this->entries[$id] = $entry;
      }
    }
  }

  /** Register one of the runtime's own services (wins over the host's). */
  public function set(string $id, object $service): void {
    $this->services[$id] = $service;
  }

  public function has(string $id): bool {
    return isset($this->services[$id]) || isset($this->entries[$id]) || ($this->host?->has($id) ?? false);
  }

  public function get(string $id): mixed {
    if (isset($this->services[$id])) {
      return $this->services[$id];
    }
    if (isset($this->entries[$id])) {
      $entry = $this->entries[$id];
      if ($entry instanceof \Closure || !is_object($entry)) {
        $entry = $entry($this);
        if (!is_object($entry)) {
          throw new \UnexpectedValueException("The factory for $id did not return an object");
        }
        $this->entries[$id] = $entry;
      }
      return $entry;
    }
    if ($this->host !== null && $this->host->has($id)) {
      return $this->host->get($id);
    }
    throw new class("DurableRuntime has no service $id (pass it in \$handlers)") extends \RuntimeException implements NotFoundExceptionInterface {};
  }

  /** The array-form handler registered for this message class, if any. */
  public function handlerFor(string $messageClass): callable|object|null {
    return $this->handlers[$messageClass] ?? null;
  }
}
