<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App;

use Doctrine\DBAL\Connection;

/** Stands in for an ORM EntityManager: flush() records whether it ran inside the transaction. */
final class RecordingFlusher {

  /** @var list<bool> */
  public array $flushes = [];

  public function __construct(private readonly Connection $connection) {}

  public function flush(): void {
    $this->flushes[] = $this->connection->isTransactionActive();
  }
}
