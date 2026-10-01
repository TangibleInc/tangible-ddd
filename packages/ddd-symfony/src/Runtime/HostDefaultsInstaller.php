<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Symfony\Lock\PooledConnectionRefused;
use TangibleDDD\Symfony\Persistence\ConnectionTopology;

/**
 * What TangibleDddBundle::boot() does to the process-wide runtime state
 * (wave-2 carry-over; register 3.9, 5.2):
 *
 * - refuses to boot when tangible_ddd.process.inband_start is true on a
 *   connection that looks pooled: an in-band first step takes a session
 *   advisory lock wherever start() is called, web requests included
 *   (scenario process.start-from-web);
 * - provides HostDefaults for core code that resolves ports itself: the sf
 *   IInfrastructureSignalDispatcher (signals reach the PSR logger and the
 *   event dispatcher, never error_log), the app clock, and the app logger
 *   for core diagnostics;
 * - registers the compiled behaviour config types (W2,
 *   `tangible_ddd.behaviour_types`), so a stored workflow decodes before any
 *   workflow handler was built.
 *
 * @internal
 */
final class HostDefaultsInstaller {

  /** @param array<string, class-string<BaseBehaviourConfig>> $behaviourTypes type => config class */
  public function __construct(
    private readonly IInfrastructureSignalDispatcher $signals,
    private readonly IClock $clock,
    private readonly Connection $connection,
    private readonly bool $inbandStart,
    private readonly ?LoggerInterface $logger = null,
    private readonly array $behaviourTypes = [],
  ) {}

  public function install(): void {
    if ($this->inbandStart) {
      $why = ConnectionTopology::pooler($this->connection->getParams());
      if ($why !== null) {
        throw new PooledConnectionRefused(
          "tangible_ddd.process.inband_start is true, but the DBAL connection looks pooled ($why). "
          . 'An in-band first step takes a session advisory lock; use a direct connection or set inband_start: false.'
        );
      }
    }

    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $this->signals);
    HostDefaults::provide(IClock::class, $this->clock);
    if ($this->logger !== null) {
      HostDefaults::provide(LoggerInterface::class, $this->logger);
    }
    // W2: the compiled behaviour types, through core's static facade (which
    // writes to the host's registry once core provides one).
    foreach ($this->behaviourTypes as $type => $class) {
      BaseBehaviourConfig::register_type((string) $type, $class);
    }
  }
}
