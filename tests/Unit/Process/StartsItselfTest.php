<?php

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsItself;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;

class SelfStartingProbe extends LongProcess {
  use StartsItself;

  public static ?ContainerInterface $test_container = null;
  public array $executed_steps = [];

  public function __construct() { parent::__construct(null); }

  protected static function container(): ContainerInterface {
    return self::$test_container;
  }

  protected function only_step(): Result {
    $this->executed_steps[] = 'only_step';
    return new Result();
  }
}

class StartsItselfTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
    $GLOBALS['wpdb'] = new \wpdb();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
    SelfStartingProbe::$test_container = null;
  }

  public function test_process_starts_itself_through_its_container(): void {
    $runner = new ProcessRunner(new FakeDDDConfig(), $repo = new FakeProcessRepository());

    SelfStartingProbe::$test_container = new class($runner) implements ContainerInterface {
      public function __construct(private readonly ProcessRunner $runner) {}
      public function get(string $id): mixed { return $this->runner; }
      public function has(string $id): bool { return ProcessRunner::class === $id; }
    };

    $process = new SelfStartingProbe();
    $process->start();

    $this->assertSame('completed', $process->status());
    $this->assertSame(['only_step'], $process->executed_steps);
    $this->assertNotNull($repo->find($process->get_id()));
  }
}
