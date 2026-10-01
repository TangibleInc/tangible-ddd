<?php
/**
 * One FRESH php process of the sf conformance host (FreshProcesses,
 * CR-W3CP-4). SfHostFixture::runFresh() starts it and writes the request
 * (base64 of a serialized array) on stdin:
 *
 *   url, schema   the test database and its per-test Postgres schema
 *   now           the host clock's current instant (ISO 8601)
 *   op, args      publish {fact, kill} | drain {} | deliver {class, wrapped} | start {process, die}
 *
 * It boots the sf composition over that schema (SfHostFixture::attach(),
 * the ddd-symfony classes the bundle wires), adds what every fresh process
 * of a conformance host adds (Support\FreshProcessBoot), does ONE thing and
 * exits. Nothing is shared with the test process but the database.
 *
 * Output: lines `@@ddd {json}` on stdout: `event` (a published event id),
 * `process` (the started process id, printed before a kill), `result`.
 * "Killed" is SIGKILL of this process at the stated point: no finally, no
 * shutdown function, no destructor runs.
 */

declare(strict_types=1);

use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\StepCommand;
use TangibleDDD\Conformance\Support\FreshProcessBoot;
use TangibleDDD\Symfony\Tests\Conformance\SfHostFixture;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$in = unserialize(base64_decode((string) stream_get_contents(STDIN)));
putenv('DDD_SF_PG_URL=' . $in['url']);

$emit = static function (array $data): void {
  fwrite(STDOUT, '@@ddd ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
  fflush(STDOUT);
};
$kill = static function (): never {
  posix_kill(getmypid(), SIGKILL);
  sleep(10);
  exit(137);
};

// A console worker on a direct connection: a start here runs in-band, as
// FreshProcesses::startInFreshProcess() requires; other ops use the bundle default.
$host = SfHostFixture::attach($in['schema'], new DateTimeImmutable($in['now']), $in['op'] === 'start' ? StartMode::InBand : StartMode::Deferred);
$runner = $host->worker(1)->processRunner();
FreshProcessBoot::boot($runner, $host->subscriptions(), $host->scenarioRows(), $host->boundary());

$args = $in['args'];
try {
  switch ($in['op']) {
    case 'publish':
      $fact = $args['fact'];
      $host->commandBus([CreateWidget::class => static function () use ($host, $fact): void {
        $host->events()->record($fact);
      }])->handle(new CreateWidget('fresh-publish'));
      $emit(['event' => PublishedFacts::id_of($fact)]);
      if ($args['kill']) {
        $kill(); // right after COMMIT, before any relay
      }
      $emit(['result' => []]);
      break;

    case 'drain':
      $report = $host->worker(1)->drainOnce();
      $emit(['result' => [
        'relayed' => $report->relay?->accepted ?? [],
        'delivered' => $report->delivered,
        'errors' => [...$report->errors, ...$host->lastDeliveryFailures()],
      ]]);
      break;

    case 'deliver':
      $outcome = $host->deliver($args['class'], $args['wrapped']);
      $emit(['result' => ['delivered' => 1, 'errors' => array_map(static fn (string $s) => "subscriber $s failed", $outcome->failed)]]);
      break;

    case 'start':
      $process = $args['process'];
      $die = $args['die'];
      if ($die !== null) {
        ProcessJournal::$onSend = static function (StepCommand $c) use ($die, $process, $emit, $kill): void {
          if ($c->label === $die) {
            $emit(['process' => $process->get_id()]);
            $kill(); // the step's command committed; the checkpoint is not saved
          }
        };
      }
      $runner->start($process);
      $emit(['result' => ['processId' => $process->get_id()]]);
      break;

    default:
      throw new InvalidArgumentException("unknown op {$in['op']}");
  }
} catch (Throwable $e) {
  $emit(['result' => ['errors' => [get_class($e) . ': ' . $e->getMessage()]]]);
}

$host->detach();
