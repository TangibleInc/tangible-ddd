<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/** What a durable wakeup does when it comes due (register 3.6). Values are persisted. */
enum WakeKind: string {
  case Continue = 'continue';
  case Timeout = 'timeout';
  case ResumeRetry = 'resume_retry';
  case Deliver = 'deliver';
}
