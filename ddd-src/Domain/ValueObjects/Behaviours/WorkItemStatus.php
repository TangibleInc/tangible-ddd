<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

enum WorkItemStatus: string {
  case pending = 'pending';
  case waiting = 'waiting';
  case failed = 'failed';
  case done = 'done';
  case skipped = 'skipped';
}


