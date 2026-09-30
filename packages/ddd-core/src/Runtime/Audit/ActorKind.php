<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/** Who is acting (register 3.9, D5). Values are persisted in audit rows. */
enum ActorKind: string {
  case User = 'user';
  case Cli = 'cli';
  case System = 'system';
  case Machine = 'machine';
}
