<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Wave 5, several consumers: the ONE Messenger handler for a ddd message
 * class, routing each message to its consumer's handler (Messenger calls
 * every handler of a class, so per-consumer handlers cannot all be tagged).
 *
 * - IntegrationFactMessage: by recipient() (the raiser, or the consumer a
 *   copy was routed to) to that consumer's IntegrationFactHandler;
 * - ProcessWakeupMessage: by `consumer` (the intent's consumer) to that
 *   consumer's ProcessWakeupHandler.
 *
 * A consumer this app does not serve is an
 * UnrecoverableMessageHandlingException (no retry, to the failure transport).
 * The handler's result and exceptions pass through unchanged.
 */
final class ConsumerRouter {

  /** @param ContainerInterface $handlers consumer prefix → handler (a ServiceLocator) */
  public function __construct(private readonly ContainerInterface $handlers) {}

  public function __invoke(IntegrationFactMessage|ProcessWakeupMessage $message): mixed {
    $consumer = $message instanceof IntegrationFactMessage ? $message->recipient() : $message->consumer;
    if (!$this->handlers->has($consumer)) {
      throw new UnrecoverableMessageHandlingException(sprintf(
        '%s for consumer "%s", which this app does not serve.', $message::class, $consumer
      ));
    }
    return ($this->handlers->get($consumer))($message);
  }
}
