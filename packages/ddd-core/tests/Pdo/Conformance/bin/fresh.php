<?php

/**
 * One fresh php process of the pdo conformance host (FreshProcesses,
 * register section 4 multi-process ids). It boots the way a raw-PHP host
 * does in production: its own PDO on the test database, then ONE
 * DurableRuntime::compose() call (in-band starts opted into through
 * HostDefaults, EnvOffsetClock for DDD_CLOCK_OFFSET), then
 * Support\FreshProcessBoot::boot() (the conformance effect subscriber, the
 * process wiring, the journal bound to the scenario table).
 *
 *   php fresh.php <spec file>     (written by Support\FreshProcessRunner)
 *
 * Operations: publish (one committed command; `kill` = SIGKILL right after
 * the COMMIT, before any relay), drain (one DurableRuntime::drain() pass),
 * deliver (the host delivery runner once), start (ProcessRunner::start();
 * `dieAfterCommand` = SIGKILL right after that step command committed).
 *
 * Answers with one line: FreshProcessRunner::MARKER + JSON. A kill is
 * posix_kill(getmypid(), SIGKILL): no finally, no shutdown function, no
 * destructor runs after it.
 */

declare(strict_types=1);

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\StepCommand;
use TangibleDDD\Conformance\Support\ConformanceConfig;
use TangibleDDD\Conformance\Support\FreshProcessBoot;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\ConformanceDatabase;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\FreshProcessRunner;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\PdoScenarioRows;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\PublishFact;
use TangibleDDD\Defaults\Pdo\DurableRuntime;
use TangibleDDD\Defaults\Pdo\PdoConnection;
use TangibleDDD\Defaults\Pdo\PdoDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Testing\EnvOffsetClock;

$root = dirname(__DIR__, 6);
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('TangibleDDD\\Conformance\\', $root . '/packages/ddd-conformance/src/');
$loader->addPsr4('TangibleDDD\\Core\\Tests\\Pdo\\Conformance\\', dirname(__DIR__) . '/');

/** @param array<string, mixed> $answer */
$answer = static function (array $answer): void {
  echo FreshProcessRunner::MARKER . json_encode($answer, JSON_THROW_ON_ERROR) . "\n";
  fflush(STDOUT);
};
$die = static function () use ($answer): never {
  posix_kill(getmypid(), SIGKILL);
  exit(137); // not reached
};

$spec = unserialize((string) file_get_contents($argv[1] ?? throw new \InvalidArgumentException('usage: fresh.php <spec file>')));
['op' => $op, 'args' => $args, 'database' => $database, 'prefix' => $prefix, 'version' => $version, 'emulate' => $emulate] = $spec;
$tablePrefix = $prefix . '_';

// ── production boot ─────────────────────────────────────────────────────────
HostDefaults::provide(StartMode::class, StartMode::InBand); // the pdo host's start mode
$db = new PdoConnection(ConformanceDatabase::connect($database, $emulate));
$clock = new EnvOffsetClock();
$fact = $args['fact'] ?? null;
$runtime = null;
$runtime = DurableRuntime::compose(
  $db,
  new ConformanceConfig($prefix, $version),
  [PublishFact::class => static function () use (&$runtime, $fact): void {
    $runtime->container()->get(EventsUnitOfWork::class)->record($fact);
  }],
  [],
  [],
  $clock,
);

// The subscriptions compose() registered (the runner's registry; see the change requests).
/** @var ISubscriptionRegistry $registry */
$registry = (fn () => $this->subscriptions)->call($runtime->runner());
$rows = new PdoScenarioRows($db, $tablePrefix);
FreshProcessBoot::boot($runtime->runner(), $registry, $rows, $runtime->boundary());

// ── the operation ───────────────────────────────────────────────────────────
try {
  switch ($op) {
    case 'publish':
      $runtime->bus()->handle(new PublishFact());
      $eventId = PublishedFacts::id_of($fact);
      $answer(['eventId' => $eventId]);
      if ($args['kill']) {
        $die(); // after COMMIT, before any relay
      }
      break;

    case 'drain':
      $jobs = "`{$tablePrefix}ddd_jobs`";
      $before = [];
      foreach ($db->fetchAll("SELECT id, attempts FROM $jobs WHERE kind = 'deliver'") as $r) {
        $before[(int) $r['id']] = (int) $r['attempts'];
      }
      $report = $runtime->drain();
      $errors = $report->errors;
      foreach ($db->fetchAll("SELECT id, event_id, attempts, last_error FROM $jobs WHERE kind = 'deliver'") as $r) {
        if ((int) $r['attempts'] > ($before[(int) $r['id']] ?? 0)) {
          $errors[] = "delivery of {$r['event_id']} needs a retry: {$r['last_error']}";
        }
      }
      $answer([
        'relayed' => $report->relay?->accepted ?? [],
        'delivered' => $report->delivered,
        'errors' => array_values($errors),
      ]);
      break;

    case 'deliver':
      $delivery = new IntegrationDelivery($registry, new PdoDeliveryLedger($db, $tablePrefix, $clock), IntegrationDelivery::DEFAULT_BUDGET);
      $outcome = $delivery->deliver($args['eventClass'], $args['wrapped']);
      $answer(['delivered' => 1, 'errors' => array_map(static fn (string $s) => "subscriber $s failed", $outcome->failed)]);
      break;

    case 'start':
      $process = $args['process'];
      if ($args['dieAfterCommand'] !== null) {
        $label = $args['dieAfterCommand'];
        ProcessJournal::$onSend = static function (StepCommand $c) use ($label, $process, $answer, $die): void {
          if ($c->label === $label) {
            $answer(['processId' => $process->get_id()]);
            $die(); // the step's command committed; its checkpoint is not saved
          }
        };
      }
      $runtime->runner()->start($process);
      $answer(['processId' => $process->get_id()]);
      break;

    default:
      throw new \InvalidArgumentException("unknown fresh-process operation '$op'");
  }
} catch (\Throwable $e) {
  $answer(['errors' => [get_class($e) . ': ' . $e->getMessage()]]);
  exit(0);
}
exit(0);
