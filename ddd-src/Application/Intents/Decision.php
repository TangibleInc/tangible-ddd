<?php

namespace TangibleDDD\Application\Intents;

enum Decision: string {
  case undecided = 'undecided';
  case fulfilled = 'fulfilled';
  case rejected = 'rejected';
}
