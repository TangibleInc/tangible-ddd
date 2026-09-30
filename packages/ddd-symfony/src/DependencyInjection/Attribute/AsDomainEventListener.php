<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Attribute;

/**
 * A synchronous, in-transaction reaction to a domain event (class or marker
 * interface, D2) on the bundle's OrderedListenerDispatcher. Its first
 * exception rolls the command back. Repeatable.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class AsDomainEventListener {

  public function __construct(
    public readonly string $event,
    public readonly int $priority = 10,
    public readonly string $method = '__invoke',
  ) {}
}
