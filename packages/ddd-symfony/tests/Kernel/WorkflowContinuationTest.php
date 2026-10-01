<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Tests\Kernel\App\Workflows\ChunkedDigestWorkflow;
use TangibleDDD\Symfony\Workflow\WorkflowContinuations;

/**
 * W1 (TXP process-kernel demands): WorkflowHandler::reschedule() on
 * ddd-symfony is a durable `ddd_wakeups` intent (ReschedulesThroughWakeups),
 * projected by `ddd:relay` and run by `messenger:consume ddd_wakeups`, which
 * continues the workflow from where it stood. A workflow that hits its
 * resource limit after every item runs each item exactly once across three
 * continuations and completes; a stale continuation is a no-op.
 */
final class WorkflowContinuationTest extends KernelTestBase {

  private function digest(): ChunkedDigestWorkflow {
    return self::getContainer()->get('test.chunked_digest');
  }

  public function test_a_workflow_out_of_resources_continues_through_wakeups_until_complete(): void {
    $workflow = $this->digest()->start('d1');
    $id = (int) $workflow->get_id();

    self::assertSame(['a'], $this->items(), 'the first run did one item');
    $intent = $this->db->fetchAssociative('SELECT * FROM ddd_wakeups');
    self::assertIsArray($intent);
    self::assertSame('continue', $intent['kind']);
    self::assertNull($intent['process_id']);
    self::assertSame('workflow:' . ChunkedDigestWorkflow::class . ":$id:0:1#1", $intent['idempotency_key']);

    $this->drain();

    self::assertSame(['a', 'b', 'c'], $this->items(), 'every item once');
    self::assertTrue((bool) $this->db->fetchOne('SELECT is_complete FROM ddd_behaviour_workflows WHERE id = ?', [$id]));
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_wakeups'), 'every continuation completed');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM messenger_messages'));
    self::assertSame([], self::getContainer()->get('test.operator_view')->list(Layer::Wakeup));
  }

  public function test_a_continuation_of_a_finished_workflow_is_a_no_op(): void {
    $workflow = $this->digest()->start('d2');
    $this->drain();
    self::assertSame(['a', 'b', 'c'], $this->items());

    // A stray continuation (e.g. a duplicate) for the finished workflow.
    self::getContainer()->get('test.workflow_continuations')->schedule(ChunkedDigestWorkflow::class, $workflow, 0);
    $this->drain();

    self::assertSame(['a', 'b', 'c'], $this->items(), 'nothing ran again');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_wakeups'));
  }

  public function test_a_reschedule_inside_a_wake_of_the_same_step_takes_the_next_number(): void {
    $continuations = self::getContainer()->get('test.workflow_continuations');
    $workflow = $this->digest()->start('d3');
    $first = (string) $this->db->fetchOne('SELECT idempotency_key FROM ddd_wakeups');

    $again = $continuations->schedule(ChunkedDigestWorkflow::class, $workflow, 0);
    self::assertSame($first, $again->key, 'outside a wake: the same key, deduplicated');
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_wakeups'));

    $next = $continuations->within($first, fn () => $continuations->schedule(ChunkedDigestWorkflow::class, $workflow, 0));
    self::assertStringEndsWith('#2', $next->key);
    self::assertSame(WorkflowContinuations::parse($first)['step'], WorkflowContinuations::parse($next->key)['step']);
  }

  /** @return list<string> */
  private function items(): array {
    return array_map('strval', $this->db->fetchFirstColumn("SELECT widget_id FROM app_listener_runs WHERE listener = 'digest-item' ORDER BY id"));
  }

  /** ddd:relay --once, then one message from whichever queue has a due one, until nothing moves. */
  private function drain(): void {
    for ($i = 0; $i < 30; $i++) {
      $this->console('ddd:relay', ['--once' => true]);
      $queue = $this->db->fetchOne(
        'SELECT queue_name FROM messenger_messages WHERE delivered_at IS NULL AND available_at <= now() ORDER BY id LIMIT 1'
      );
      if ($queue === false) {
        return;
      }
      $consume = $this->console('messenger:consume', ['receivers' => [$queue], '--limit' => 1, '--time-limit' => 10]);
      self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay() . $consume->getErrorOutput());
    }
    self::fail('the workers did not go idle');
  }
}
