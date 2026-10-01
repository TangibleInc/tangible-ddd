<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Symfony\Runtime\Actor\ActorContext;

/**
 * The worker / request boundary reset (register 3.9, E section 8).
 *
 * Runs the core RuntimeReset::betweenMessages() after every handled or
 * failed Messenger message (lowest priority, after Messenger's own retry and
 * failure listeners) and on `kernel.reset` (the services resetter between
 * messages and, under a long-running HTTP runtime, between requests).
 *
 * install() registers the bundle's per-message state with RuntimeReset
 * once, at Bundle::boot(): the EventsUnitOfWork the command bus drains and
 * the explicit ActorContext. Correlation and Reactions are reset by core.
 * ConsumerRegistry and HostDefaults are boot-time and never touched.
 *
 * A leak (open Correlation scope, a held process lock, a failing resetter)
 * has already been cleaned when RuntimeLeakDetected arrives; it is logged at
 * CRITICAL, so it is loud in every environment, and the worker continues
 * with a clean runtime.
 */
final class DddRuntimeReset implements ResetInterface, EventSubscriberInterface {

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly EventsUnitOfWork $events,
    private readonly ActorContext $actors,
    ?LoggerInterface $logger = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  public static function getSubscribedEvents(): array {
    return [
      WorkerMessageHandledEvent::class => ['onMessageHandled', -1024],
      WorkerMessageFailedEvent::class => ['onMessageFailed', -1024],
    ];
  }

  public function install(): void {
    RuntimeReset::register('tangible_ddd.symfony.events_unit_of_work', fn () => $this->events->reset());
    RuntimeReset::register('tangible_ddd.symfony.actor_context', fn () => $this->actors->reset());
  }

  public function onMessageHandled(WorkerMessageHandledEvent $event): void {
    $this->betweenMessages('after handling a message from ' . $event->getReceiverName());
  }

  public function onMessageFailed(WorkerMessageFailedEvent $event): void {
    $this->betweenMessages('after a failed message from ' . $event->getReceiverName());
  }

  public function reset(): void {
    $this->betweenMessages('on kernel.reset');
  }

  private function betweenMessages(string $when): void {
    try {
      RuntimeReset::betweenMessages();
    } catch (RuntimeLeakDetected $leak) {
      $this->logger->critical('[ddd reset] ' . $leak->getMessage() . " ($when)", ['exception' => $leak]);
    }
  }
}
