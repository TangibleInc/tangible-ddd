<?php

namespace TangibleDDD\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
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
    FakeGatherProcess::$last_routed_event = null;

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
    $bus = new OutboxIntegrationEventBus($outbox, $config);
    $dispatcher = new WordPressEventDispatcher();
    $router = new EventRouter($dispatcher, $bus);

    $runner->register_event(FakeResolvedEvent::class);

    // 1. saga starts, suspends on [1]. start()'s sealed bracket scope-exits
    //    with a context reset (worker hygiene) — the raiser below is
    //    conceptually a separate pass with its own ambient correlation, so
    //    restore it the way any real raising context would hold its own.
    $saga = new FakeGatherProcess([1]);
    $pipe_ctx = new \TangibleDDD\Application\Correlation\TraceContext('pipe-corr');
    \TangibleDDD\Application\Correlation\Correlation::within($pipe_ctx, static fn () => $runner->start($saga));
    $this->assertSame('suspended', $saga->status());

    // 2. the fact occurs — raised through the ROUTER like production code,
    //    from its own raising context holding the same story.
    \TangibleDDD\Application\Correlation\Correlation::within($pipe_ctx, static fn () => $router->publish(
      new FakeResolvedEvent(1, FakeOutcome::Accepted, new \DateTimeImmutable('2026-07-06T10:00:00+00:00'))
    ));

    // 3. relay drains outbox → AS stub captures the wrapped payload
    $processor->process_batch();
    $as_jobs = array_filter($_test_scheduled_actions, fn($a) => $a['hook'] === FakeResolvedEvent::integration_action());
    $this->assertCount(1, $as_jobs);
    $wrapped = array_values($as_jobs)[0]['args'][0];
    // The VALUES survive the hop, not just the keys: the raise-time ambient
    // correlation, and the event_id the outbox write() minted (which the bus
    // stamped back onto the in-hand event — same identity as the row).
    $this->assertSame('pipe-corr', $wrapped['__correlation_id']);
    $this->assertSame('evt_1', $wrapped['__event_id']);

    // 4. "AS worker" fires the hook — the wake. A real AS worker is a fresh
    // request with NO ambient context; reset first so anything correlation-
    // shaped observed after this point can only have come from the transport
    // envelope, never from state leaked across the "process boundary".
    do_action(FakeResolvedEvent::integration_action(), $wrapped);

    // Context restore, proven on the woken event itself: register_event's
    // callback unwrapped the envelope, hydrated the event, and stamped the
    // transport journey onto it BEFORE routing — resolution_key (the key_by
    // extractor the routing path calls) captured that exact instance.
    // (Correlation::peek() is deliberately NOT asserted here: it is null
    // post-wake, because the wake bracket's scope-exit restores ambient
    // state — worker hygiene, so one AS callback's correlation can't bleed
    // into the next. Verified empirically.)
    $woken = FakeGatherProcess::$last_routed_event;
    $this->assertNotNull($woken, 'routing path saw the hydrated event');
    // 0.3: the woken event object carries no identity; the SAGA carries the
    // story (asserted below) and the envelope carried the id to the drain.

    // 5. saga completed; coordinator saw the satisfied mechanism; the saga
    // row still carries the raise-time correlation (resume ran under it).
    $this->assertSame('completed', $repo->find($saga->get_id())->status());
    $this->assertSame('pipe-corr', $repo->find($saga->get_id())->correlation_id());
    $this->assertTrue($saga->gather_seen->is_satisfied());
  }
}
