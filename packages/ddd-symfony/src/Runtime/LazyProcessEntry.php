<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Application\Process\ResumeReport;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Process\IProcessEntry;

/**
 * IProcessEntry that resolves the process runner on first use. Breaks the
 * construction cycle runner → subscription registry → runner (the runner
 * reads the registry to check that an awaited fact has a resume
 * subscriber), and keeps web requests that deliver no facts from building
 * the runner at all.
 *
 * @internal
 */
final class LazyProcessEntry implements IProcessEntry {

  private ?IProcessEntry $entry = null;

  /** @param \Closure(): IProcessEntry $resolve */
  public function __construct(private readonly \Closure $resolve) {}

  public function ignite(string $processClass, IIntegrationEvent $event, string $eventId): void {
    $this->entry()->ignite($processClass, $event, $eventId);
  }

  public function resume(IIntegrationEvent $event): void {
    $this->entry()->resume($event);
  }

  /**
   * AW3: resume() with what it did, when the runner reports it
   * (ProcessRunner::resume_with_outcome()); null when the entry cannot say.
   */
  public function resume_with_outcome(IIntegrationEvent $event): ?ResumeReport {
    $entry = $this->entry();
    if (method_exists($entry, 'resume_with_outcome')) {
      $report = $entry->resume_with_outcome($event);
      return $report instanceof ResumeReport ? $report : null;
    }
    $entry->resume($event);
    return null;
  }

  private function entry(): IProcessEntry {
    return $this->entry ??= ($this->resolve)();
  }
}
