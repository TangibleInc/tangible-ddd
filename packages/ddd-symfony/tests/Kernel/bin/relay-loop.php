<?php
/**
 * A separate `ddd:relay` worker process for the D14 kernel test
 * (PostCommitWakeupTest): boots TestKernel in the given variant and runs the
 * real `ddd:relay` loop with the given options, as a supervisor would run
 * `bin/console ddd:relay`. The test process shares nothing with it but the
 * database (DDD_SF_PG_URL is inherited).
 *
 *   php relay-loop.php <variant> [ddd:relay options...]
 */

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use TangibleDDD\Symfony\Tests\Kernel\App\TestKernel;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$variant = $argv[1] ?? 'default';
$kernel = new TestKernel('test', true, $variant);
$kernel->boot();

$application = new Application($kernel);
$application->setAutoExit(false);
exit($application->run(new ArgvInput(['relay-loop', 'ddd:relay', ...array_slice($argv, 2)]), new ConsoleOutput()));
