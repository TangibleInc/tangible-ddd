<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessSteps;
use TangibleDDD\Application\Process\Repair\ProcessNotStranded;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Tests\Integration\V8\Fakes\V8ManualProcess;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\WpdbProcessStore;
use TangibleDDD\WordPress\Adapter\WpOperatorView;
use TangibleDDD\WordPress\Adapter\WpStrandedRepairs;

/**
 * WP8-10: the stranded `running` repairs on wp. The operator view labels a
 * stranded `running` row `resume-stranded` / `fail-stranded`, and
 * WpStrandedRepairs (behind `wp ddd ops --resume-stranded=<id>` /
 * `--fail-stranded=<id>`) dispatches core's ResumeStrandedProcess /
 * FailStrandedProcess on the v8 ports.
 */
final class WpStrandedRepairV8Test extends V8TestCase {

  private FrozenClock $clock;

  private WpdbProcessStore $store;

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    $this->clock = new FrozenClock(new \DateTimeImmutable('@' . time()));
    HostDefaults::provide(IClock::class, $this->clock);
    $this->store = new WpdbProcessStore(new ProcessRepository($this->config), $this->config, $this->clock);
  }

  /** A process left `running` (its worker died mid-step) 16 minutes ago, with no live intent. */
  private function strandedRunning(): int {
    $p = new V8ManualProcess(7);
    $p->initialize_lifecycle('33333333-3333-4333-8333-333333333333', ProcessSteps::from_reflection([new \ReflectionMethod($p, 'react')], []));
    $p->advance(status: 'running', payload: null);
    $id = $this->store->insert($p);
    $old = $this->clock->now()->modify('-16 minutes')->format('Y-m-d H:i:s');
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET updated_at = '$old' WHERE id = $id");
    return $id;
  }

  /** @return array<string, mixed> */
  private function row(int $id): array {
    return $this->rows("SELECT * FROM `{$this->table('long_processes')}` WHERE id = $id")[0];
  }

  public function test_the_operator_view_labels_a_stranded_running_row_with_both_repairs(): void {
    $id = $this->strandedRunning();

    $items = array_values(array_filter(
      (new WpOperatorView($this->config, $this->clock))->list('process'),
      static fn (array $i) => str_starts_with($i['key'], "#$id "),
    ));

    self::assertCount(1, $items);
    self::assertSame(['resume-stranded', 'fail-stranded'], $items[0]['repair_actions']);
  }

  public function test_resume_stranded_writes_a_resume_retry_intent_projected_for_a_worker(): void {
    $id = $this->strandedRunning();
    $version = (int) $this->row($id)['version'];

    (new WpStrandedRepairs($this->config, $this->clock))->resume($id);

    self::assertSame((string) ($version + 1), $this->row($id)['version'], 'the row is fenced first');
    self::assertSame('running', $this->row($id)['status'], 'no step runs inside the repair');
    $intents = $this->rows("SELECT kind, process_id, status FROM `{$this->table('ddd_wakeups')}`");
    self::assertSame([['kind' => 'resume_retry', 'process_id' => (string) $id, 'status' => 'pending']], $intents);
    self::assertCount(1, $this->pendingActions($this->config->hook('ddd_wakeup')), 'a worker runs the re-run');
    self::assertSame([], $this->store->findStranded($this->clock->now()), 'no longer stranded: it has a live intent');
  }

  public function test_fail_stranded_fails_the_row_with_the_operator_reason(): void {
    $id = $this->strandedRunning();

    (new WpStrandedRepairs($this->config, $this->clock))->fail($id, 'card expired');

    $row = $this->row($id);
    self::assertSame('failed', $row['status']);
    self::assertStringContainsString('Failed by operator: card expired', (string) $row['last_error']);
    self::assertSame([], $this->rows("SELECT id FROM `{$this->table('ddd_wakeups')}`"));
  }

  public function test_a_repair_is_refused_while_a_worker_holds_the_process_lock(): void {
    $id = $this->strandedRunning();
    $other = \TangibleDDD\Tests\Integration\Conformance\Support\ConnectionSwitch::open($this->wpdb);
    try {
      $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', GetLockProcessLock::legacyName(new LockKey($this->config->prefix(), '', $id))));
      $this->expectException(ProcessNotStranded::class);
      (new WpStrandedRepairs($this->config, $this->clock))->fail($id, 'nope');
    } finally {
      $other->close();
    }
  }

  public function test_a_row_that_is_not_stranded_is_refused(): void {
    $id = $this->strandedRunning();
    $this->wpdb->query($this->wpdb->prepare("UPDATE `{$this->table('long_processes')}` SET updated_at = %s WHERE id = %d", $this->clock->now()->format('Y-m-d H:i:s'), $id));

    $this->expectException(ProcessNotStranded::class);
    (new WpStrandedRepairs($this->config, $this->clock))->resume($id);
  }
}
