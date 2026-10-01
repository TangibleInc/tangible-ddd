<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TangibleDDD\Domain\Exceptions\ConflictException;

/**
 * A command's writes conflicted with a unique constraint when the configured
 * EntityManager was flushed before COMMIT (L8). The transaction was rolled
 * back and nothing was committed; the host maps it to 409 Conflict. The DBAL
 * UniqueConstraintViolationException is the previous exception, and
 * $constraint is the violated constraint's name when Postgres reports it.
 *
 * A core ConflictException (L10, wave 5), so an application catches the 409
 * family without depending on this package. Since wave 5 it is no longer a
 * \RuntimeException: a `catch (\RuntimeException)` around a command stops
 * catching it (catch ConflictException, or \Exception).
 *
 * A unique violation raised inside the handler's own work (a DBAL insert the
 * handler issues) is NOT translated: the handler sees it at the statement
 * and may handle it there.
 */
final class PersistenceConflict extends ConflictException {

  public function __construct(string $message, public readonly ?string $constraint, UniqueConstraintViolationException $previous) {
    parent::__construct($message, 409, $previous);
  }

  /** The conflict for a unique violation anywhere in $e's chain, or null. */
  public static function find_in(\Throwable $e): ?self {
    for ($t = $e; $t !== null; $t = $t->getPrevious()) {
      if ($t instanceof UniqueConstraintViolationException) {
        $constraint = preg_match('/unique constraint "([^"]+)"/', $t->getMessage(), $m) === 1 ? $m[1] : null;
        return new self(
          'The command conflicts with existing data (unique constraint ' . ($constraint ?? 'unknown') . '); nothing was committed.',
          $constraint,
          $t
        );
      }
    }
    return null;
  }
}
