<?php

namespace TangibleDDD\Application\Exceptions;

/**
 * A SelfHandlingCommand's handle() parameter could not be method-injected.
 *
 * Method injection resolves each parameter by its named, non-builtin class
 * type from the container (the same shape as Symfony's ArgumentResolver).
 * A parameter that is untyped, builtin-typed, or a union/intersection type —
 * with no default to fall back on — has nothing the container can hand it.
 *
 * The fix: give the parameter a resolvable class type (a service the
 * container knows), or a default value.
 */
final class UnresolvableHandleDependency extends ApplicationException {

  public function __construct(string $command, string $parameter) {
    parent::__construct(sprintf(
      'Cannot method-inject $%s into %s::handle() — a handle() parameter must '
      . 'have a resolvable class type (a container service) or a default '
      . 'value. Untyped, builtin, and union/intersection params are not '
      . 'injectable.',
      $parameter,
      $command
    ));
  }
}
