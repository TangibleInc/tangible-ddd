<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbConsumer;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbGatherSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbHopSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbJournal;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbNote;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbOrderPlaced;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbOrderSaga;
use TangibleDDD\Tests\Compat\Rollback\Fixtures\RbPaymentReceived;
use TangibleDDD\Tests\Compat\Rollback\Support\RollbackTestCase;
use TangibleDDD\WordPress\Adapter\WpDeliveryLedger;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;
use TangibleDDD\WordPress\Adapter\WpRollbackDrain;

/**
 * Register 7.3, second half: N WRITES (schema v8, its own paths), then the
 * winner switches back to a legacy copy (L-0.6.6, L-0.6.5, L-0.6.2), whose
 * queued work drains and decodes.
 *
 * N-only artifacts covered: future-dated wakeup intents (their Action
 * Scheduler actions on the legacy hooks with the legacy args fire under
 * 0.6), a claimed outbox row (claim_token + locked_until / locked_by), a
 * row with ignition_key, a quarantined row (status failed +
 * quarantine_reason), a failed delivery-ledger row and its pending
 * `{prefix}_ddd_redeliver` action: lost under 0.6 unless `wp ddd drain
 * --before-rollback` ran first (both paths).
 */
#[Group('compat')]
#[Group('rollback')]
final class NRowsRolledBackRollback extends RollbackTestCase {

  private const IGNITING_EVENT = 'e2000000-0000-4000-8000-000000000001';

  #[DataProvider('legacyVersions')]
  public function test_what_n_wrote_drains_and_decodes_under_a_rolled_back_legacy_winner(string $version): void {
    $legacy = $this->legacy($version);
    $this->nInstall();
    $this->nBoot();

    // ── N's site ────────────────────────────────────────────────────────────
    [$relayed] = $this->nPublish(new RbNote('relayed'));
    $this->nTick(); // relayed: a pending integration action (N's transport, 0.6 shape)
    self::assertSame('completed', $this->outboxRow($relayed)['status'], 'N writes completed, never accepted');
    [$claimed] = $this->nPublish(new RbNote('claimed'));
    $claims = $this->outbox->claim(10, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 300); // a relay that died after its claim
    self::assertSame([$claimed], array_map(static fn ($c) => $c->event_id, $claims));
    [$pending, $delayed] = $this->nPublish(new RbNote('pending'), new RbNote('delayed', 120));
    $claimedRow = $this->outboxRow($claimed);
    self::assertNotNull($claimedRow['claim_token']);
    self::assertNotNull($claimedRow['locked_until']);
    self::assertNotNull($claimedRow['locked_by']);
    self::assertSame('0', (string) $this->outboxRow($delayed)['delay_seconds'], 'N stores the absolute due time only (bug 3)');

    $this->nDeliver(new RbOrderPlaced('o-n'), self::IGNITING_EVENT);
    $order = $this->processIdOf(RbOrderSaga::class, 'o-n');
    self::assertNotNull($this->processRow($order)['ignition_key']);
    self::assertSame('suspended', $this->processRow($order)['status']);
    $gather = $this->nStart(new RbGatherSaga('o-g'));
    $hop = $this->nStart(new RbHopSaga('o-h'));
    self::assertSame('scheduled', $this->processRow($hop)['status']);
    self::assertCount(1, $this->pending('process_continue'), "N projected the Continue intent on the legacy hook");
    self::assertSame(['process_id' => $hop], $this->pending('process_continue')[0]['args'], 'with the legacy args');
    $alarm = $this->pending('await_timeout');
    self::assertCount(1, $alarm);
    self::assertSame(['process_id' => $gather, 'step_index' => 0], $alarm[0]['args']);
    self::assertGreaterThan(time() + RbGatherSaga::TIMEOUT_SECONDS - 60, $alarm[0]['due'], 'future-dated at its absolute instant');

    $quarantined = $this->nStart(new RbOrderSaga('o-q'));
    $this->wpdb->update($this->table('long_processes'), ['process_class' => 'TangibleDDD\\Tests\\Compat\\Rollback\\Fixtures\\GoneSaga'], ['id' => $quarantined]);
    try {
      $this->processStore->find($quarantined);
      self::fail('expected a quarantine');
    } catch (\TangibleDDD\Runtime\Process\QuarantinedProcess) {
    }
    self::assertSame('failed', $this->processRow($quarantined)['status']);
    self::assertNotNull($this->processRow($quarantined)['quarantine_reason']);

    // ── rollback: the legacy copy is the winner again ───────────────────────
    $migrated = $legacy->run('migrate');
    self::assertSame(8, $migrated['installed'], "L-$version tolerates installed v8 > its own v{$migrated['legacy_schema']} (B18): nothing re-installed or downgraded");
    self::assertSame(['installed' => 8], ['installed' => (int) get_option($this->config->option('ddd_schema_version'))]);

    self::assertSame(1, $legacy->run('relay')['completed'], 'the 0.6 relay takes the pending N row only: the claimed row stays excluded (locked_until), the delayed one is not due');
    self::assertSame('completed', $this->outboxRow($pending)['status']);
    self::assertSame('pending', $this->outboxRow($claimed)['status']);

    $due = $legacy->run('run_due');
    self::assertSame([], $due['failed'], json_encode($due));
    self::assertSame(1, RbJournal::count('note:relayed'), "the action N queued is delivered by L-$version");
    self::assertSame(1, RbJournal::count('note:pending'));
    self::assertSame(1, RbJournal::count('hop:first:o-h'), 'no step re-runs');
    // 0.6 re-schedules an #[Async] step before running it, every time (a 0.6
    // defect N fixed): the step does not run under the legacy winner, and
    // there is never more than one continuation queued.
    self::assertSame(0, RbJournal::count('hop:second:o-h'));
    self::assertLessThanOrEqual(1, count($this->pending('process_continue')));

    // An awaited fact resumes N's process under the legacy winner (it decodes N's row).
    $legacy->run('deliver', ['class' => RbPaymentReceived::class, 'params' => ['o-n'], 'event_id' => Uuid::v4()]);
    self::assertSame(1, RbJournal::count('order:settle:o-n'));
    self::assertSame('completed', $this->processRow($order)['status']);
    // A redelivery of the igniting fact does not ignite again (0.6's has_ignition sees N's row).
    $legacy->run('deliver', ['class' => RbOrderPlaced::class, 'params' => ['o-n'], 'event_id' => self::IGNITING_EVENT]);
    self::assertSame(1, RbJournal::count('order:open:o-n'));

    // Later: N's future intent fires on the legacy hook; the dead relay's lease expired; the delay is due once.
    $this->age(RbGatherSaga::TIMEOUT_SECONDS + 1);
    self::assertSame(2, $legacy->run('relay')['completed']);
    $due = $legacy->run('run_due');
    self::assertSame([], $due['failed'], json_encode($due));
    self::assertSame(1, RbJournal::count('gather:assemble:o-g:0'), "N's alarm fired under L-$version (PROCEED)");
    self::assertSame('completed', $this->processRow($gather)['status']);
    self::assertSame(1, RbJournal::count('note:claimed'), 'the claimed row is relayed once its lease expired');
    self::assertSame(1, RbJournal::count('note:delayed'));

    // Everything decodes under the legacy winner, except the quarantined row, which stays failed with its reason.
    $decoded = $legacy->run('decode')['processes'];
    foreach ([$order, $gather, $hop] as $id) {
      self::assertTrue($decoded[$id]['ok'] ?? false, "#$id decodes under L-$version: " . json_encode($decoded[$id] ?? null));
    }
    self::assertFalse($decoded[$quarantined]['ok']);
    self::assertSame('failed', $this->processRow($quarantined)['status']);
    self::assertNotNull($this->processRow($quarantined)['quarantine_reason']);

    // The legacy stats and purge see N's rows as completed.
    $stats = $legacy->run('stats')['stats'];
    self::assertSame(4, (int) ($stats['completed'] ?? -1), json_encode($stats));
  }

  #[DataProvider('legacyVersions')]
  public function test_a_pending_redelivery_is_lost_on_rollback_without_the_drain(string $version): void {
    $legacy = $this->legacy($version);
    $this->nInstall();
    $this->nBoot();
    $eventId = $this->failOnce();

    $legacy->run('migrate');
    $this->age(3600);
    $due = $legacy->run('run_due');

    self::assertSame(0, RbJournal::count('note:flaky'), 'the handler retry never ran under the legacy winner');
    self::assertNotEmpty(array_filter($due['failed'], static fn (string $f) => str_contains($f, 'no callbacks')), 'Action Scheduler failed the N-only action: lost');
    $ledger = new WpDeliveryLedger($this->config->prefix());
    self::assertFalse($ledger->delivered($this->noteSubscriber(), $eventId));
    self::assertSame(1, $ledger->attempts($this->noteSubscriber(), $eventId), 'the failed ledger row is left as N wrote it');
  }

  #[DataProvider('legacyVersions')]
  public function test_drain_before_rollback_empties_the_pending_redeliveries_first(string $version): void {
    $legacy = $this->legacy($version);
    $this->nInstall();
    $this->nBoot();
    $eventId = $this->failOnce();
    // A fact over Action Scheduler's args limit, relayed by reference (N-only: 0.6 cannot resolve it).
    $this->nPublish(new RbNote(str_repeat('x', 9000)));
    $this->nTick();
    self::assertCount(1, $this->pending('integration_rb_note'));

    $report = (new WpRollbackDrain($this->config))->run();
    self::assertSame(0, $report['remaining'], json_encode($report));
    self::assertSame(1, RbJournal::count('note:flaky'), 'redelivered by N before the switch');
    self::assertSame(1, RbJournal::count('note:len9000'), 'the by-reference fact was delivered by N before the switch');
    self::assertSame([], $this->pending('integration_rb_note'));
    self::assertTrue((new WpDeliveryLedger($this->config->prefix()))->delivered($this->noteSubscriber(), $eventId));
    self::assertSame([], $this->pending('ddd_redeliver'));

    $legacy->run('migrate');
    $this->age(3600);
    self::assertSame([], $legacy->run('run_due')['failed'], 'nothing N-only is left for the legacy winner');
    self::assertSame(1, RbJournal::count('note:flaky'));
  }

  /** The RbNote listener fails once under N: a `failed` ledger row and a pending `{prefix}_ddd_redeliver`. */
  private function failOnce(): string {
    update_option(RbConsumer::LISTENER_DOWN_OPTION, 1, false);
    $eventId = Uuid::v4();
    $this->nDeliver(new RbNote('flaky'), $eventId);
    delete_option(RbConsumer::LISTENER_DOWN_OPTION);
    self::assertSame(1, RbJournal::count('note:down:flaky'));
    self::assertCount(1, $this->pending('ddd_redeliver'));
    return $eventId;
  }

  private function noteSubscriber(): string {
    return WpLedgeredDelivery::subscribers(RbNote::integration_action())[0];
  }

  /** A fact on its hook as Action Scheduler delivers it under N (do_action with the wrapped envelope). */
  private function nDeliver(IIntegrationEvent $fact, string $eventId): void {
    do_action($fact::integration_action(), IntegrationEnvelope::wrap($fact->integration_payload(), Uuid::v4(), 1, $eventId));
  }

  private function processIdOf(string $class, string $order): int {
    $id = $this->wpdb->get_var($this->wpdb->prepare(
      "SELECT id FROM `{$this->table('long_processes')}` WHERE process_class = %s AND JSON_UNQUOTE(JSON_EXTRACT(business_data, '$.order')) = %s",
      $class,
      $order
    ));
    self::assertNotNull($id, "no $class for $order");
    return (int) $id;
  }
}
