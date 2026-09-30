<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/** Outcome of IProcessStore::insertIgnited() (X7). Identical on every host. */
enum IgnitionResult {
  case Inserted;
  case AlreadyIgnited;
}
