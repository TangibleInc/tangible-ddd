<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Health check (register 3.2, E section 5): Messenger's
 * `doctrine_transaction` middleware must never wrap the bus that runs DDD
 * delivery. It would open one transaction per message, so every subscriber's
 * command (and a process wake's several step commands) would collapse into
 * savepoints of it, and DbalTransactionBoundary would reject them anyway.
 * Compilation fails with the fix in the message.
 */
final class MessengerHealthPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    if (!$container->hasParameter('tangible_ddd.messenger.bus')) {
      return;
    }
    $bus = (string) $container->getParameter('tangible_ddd.messenger.bus');

    $middleware = [];
    if ($container->hasParameter($bus . '.middleware')) {
      foreach ((array) $container->getParameter($bus . '.middleware') as $m) {
        $middleware[] = is_array($m) ? (string) ($m['id'] ?? '') : (string) $m;
      }
    }
    if ($container->hasDefinition($bus)) {
      $argument = $container->getDefinition($bus)->getArguments()[0] ?? null;
      if ($argument instanceof IteratorArgument) {
        foreach ($argument->getValues() as $ref) {
          if ($ref instanceof Reference) {
            $middleware[] = (string) $ref;
            if ($container->hasDefinition((string) $ref)) {
              $def = $container->getDefinition((string) $ref);
              if ($def instanceof ChildDefinition) {
                $middleware[] = $def->getParent();
              }
            }
          }
        }
      }
    }

    foreach ($middleware as $id) {
      if ($id === 'doctrine_transaction' || str_ends_with($id, '.doctrine_transaction')) {
        throw new LogicException(sprintf(
          'tangible_ddd: the Messenger bus "%s" delivers DDD facts but carries the doctrine_transaction middleware. '
          . 'DDD commands own their transactions (DbalTransactionBoundary); remove doctrine_transaction from that bus, '
          . 'or point tangible_ddd.messenger.bus at a bus without it.',
          $bus
        ));
      }
    }
  }
}
