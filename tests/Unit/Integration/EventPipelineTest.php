<?php

namespace TangibleDDD\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Infra\Services\WordPressEventDispatcher;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeOutboxRepository;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

/**
 * THE test that never existed: an event travels the REAL pipe —
 * raise → router → outbox row → processor wrap (__-keys) → AS(stub) →
 * integration_action fires → runner hydrates, stamps, routes → saga wakes.
 */
class EventPipelineTest extends TestCase {

  public function test_full_pipe_wakes_the_saga(): void {
    global $_test_actions, $_test_scheduled_actions;
    $_test_actions = [];
    $_test_scheduled_actions = [];
    CorrelationContext::reset();
    CorrelationContext::init('pipe-corr');

    // ── wiring (real classes, in-memory persistence) ──
    // resume_on_event() takes a MySQL named lock via global $wpdb; the plain
    // wp-stubs wpdb::get_var() returns null (treated as "acquired").
    $GLOBALS['wpdb'] = new \wpdb();

    // runner + repo: same fake-repo wiring as ProcessRunnerAwaitAllTest's setUp
    $config = new FakeDDDConfig();
    $repo = new FakeProcessRepository();
    $runner = new ProcessRunner($config, $repo);

    // outbox: in-memory IOutboxRepository fake
    $outbox = new FakeOutboxRepository();
    $outbox_config = new OutboxConfig();

    // publisher: real ActionSchedulerOutboxPublisher (writes to $_test_scheduled_actions)
    $publisher = new ActionSchedulerOutboxPublisher($outbox_config);
    $processor = new OutboxProcessor($config, $outbox, $outbox_config, $publisher);

    // router: real EventRouter(WordPressEventDispatcher, OutboxIntegrationEventBus(outbox fake))
    $bus = new OutboxIntegrationEventBus($outbox);
    $dispatcher = new WordPressEventDispatcher();
    $router = new EventRouter($dispatcher, $bus);

    $runner->register_event(FakeResolvedEvent::class);

    // 1. saga starts, suspends on [1]
    $saga = new FakeGatherProcess([1]);
    $runner->start($saga);
    $this->assertSame('suspended', $saga->status());

    // 2. the fact occurs — raised through the ROUTER like production code
    $router->publish(new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00')));

    // 3. relay drains outbox → AS stub captures the wrapped payload
    $processor->process_batch();
    $as_jobs = array_filter($_test_scheduled_actions, fn($a) => $a['hook'] === FakeResolvedEvent::integration_action());
    $this->assertCount(1, $as_jobs);
    $wrapped = array_values($as_jobs)[0]['args'][0];
    $this->assertArrayHasKey('__correlation_id', $wrapped);
    $this->assertArrayHasKey('__event_id', $wrapped);

    // 4. "AS worker" fires the hook — the wake
    do_action(FakeResolvedEvent::integration_action(), $wrapped);

    // 5. saga completed; coordinator saw the satisfied mechanism
    $this->assertSame('completed', $repo->find($saga->get_id())->status());
    $this->assertTrue($saga->gather_seen->is_satisfied());
  }
}
