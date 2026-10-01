<?php
/**
 * One fresh php process of the wp conformance host (FreshProcesses,
 * CR-W3CP-4): boots WordPress through the conformance bootstrap, composes
 * the conformance consumer on the v8 adapters exactly as the test process
 * does (Support\WpConformanceRuntime), registers what a production boot
 * registers (the wake and redelivery hooks) plus
 * Support\FreshProcessBoot::boot(), runs ONE operation and exits.
 *
 *   php bin/fresh.php publish|drain|deliver|start <base64 json args>
 *
 * The clock is the parent's host clock (DDD_CONFORMANCE_NOW). Output: one
 * JSON object per line on stdout (Support\FreshPhp merges them). A kill is
 * SIGKILL of this process at the stated point: no finally, no shutdown
 * function, no destructor runs after it.
 */

declare(strict_types=1);

use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\StepCommand;
use TangibleDDD\Conformance\Support\FreshProcessBoot;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Tests\Integration\Conformance\Support\BufferLogger;
use TangibleDDD\Tests\Integration\Conformance\Support\WpConformanceRuntime;

require dirname(__DIR__) . '/bootstrap.php';

$emit = static function (array $data): void {
  fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
  fflush(STDOUT);
};
$kill = static function (): never {
  fflush(STDOUT);
  posix_kill(getmypid(), 9); // SIGKILL
  sleep(10);
  exit(137);
};

$op = $argv[1] ?? '';
$args = json_decode((string) base64_decode($argv[2] ?? '', true), true);
$args = is_array($args) ? $args : [];
$now = getenv('DDD_CONFORMANCE_NOW');
if ($now === false || !ctype_digit($now)) {
  fwrite(STDERR, "fresh.php: DDD_CONFORMANCE_NOW is not set\n");
  exit(2);
}

$rt = new WpConformanceRuntime(new FrozenClock(new DateTimeImmutable('@' . $now)), new BufferLogger());
$rt->provideHostDefaults();
$rt->registerHooks();
RuntimeReset::register('conformance.events', static fn () => $rt->events->reset());
RuntimeReset::guard($rt->lock);
FreshProcessBoot::boot($rt->runner, $rt->subscriptions, $rt->rows, $rt->boundary);
$emit(['conn' => (int) $GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()')]);

try {
  switch ($op) {
    case 'publish':
      $fact = unserialize(base64_decode((string) $args['fact']));
      $bus = $rt->commandBus([CreateWidget::class => static function () use ($rt, $fact): void {
        $rt->events->record($fact);
      }]);
      $bus->handle(new CreateWidget('fresh-publish'));
      $emit(['eventId' => PublishedFacts::id_of($fact)]);
      if (!empty($args['kill'])) {
        $kill(); // right after the COMMIT, before any relay
      }
      break;

    case 'drain':
      $pass = $rt->drainPass($rt->runner);
      $emit([
        'relayed' => $pass['relayed'],
        'delivered' => $pass['report']->delivered,
        'errors' => $pass['report']->errors,
      ]);
      break;

    case 'deliver':
      $outcome = $rt->deliver((string) $args['eventClass'], (array) $args['wrapped']);
      $emit([
        'delivered' => 1,
        'errors' => array_map(static fn (string $s) => "subscriber $s failed", $outcome->failed),
      ]);
      break;

    case 'start':
      $process = unserialize(base64_decode((string) $args['process']));
      $die = $args['die'] ?? null;
      if (is_string($die)) {
        ProcessJournal::$on_send = static function (StepCommand $c) use ($die, $process, $emit, $kill): void {
          if ($c->label === $die) {
            $emit(['processId' => $process->get_id()]);
            $kill(); // the step's command committed; its checkpoint is not saved
          }
        };
      }
      $rt->runner->start($process);
      $emit(['processId' => $process->get_id()]);
      break;

    default:
      fwrite(STDERR, "fresh.php: unknown operation '$op'\n");
      exit(2);
  }
} catch (Throwable $e) {
  $emit(['errors' => [get_class($e) . ': ' . $e->getMessage()]]);
}
exit(0);
