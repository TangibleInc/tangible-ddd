<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\IDDDConfig;

/**
 * The act bracket (0.3, spec §6.2 + build ruling #1): CorrelationMiddleware
 * owns guard + scope + the audit record — the audit write happens at
 * bracket-open, where the ENCLOSING cause is still visible (two separate
 * middlewares can't both see the parent and own the scope; OTel's answer).
 */
class ActBracketTest extends TestCase {

  /** @var array<int, array> */
  private array $inserts = [];
  /** @var array<int, array> */
  private array $updates = [];

  private EventsUnitOfWork $uow;

  protected function setUp(): void {
    Correlation::reset();
    Reactions::reset();
    \TangibleDDD\Application\Events\ActFacts::reset();
    $this->inserts = [];
    $this->updates = [];
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function make_config(string $prefix): IDDDConfig {
    return new class($prefix) implements IDDDConfig {
      public function __construct(private string $p) {}
      public function prefix(): string { return $this->p; }
      public function table(string $name): string { return 'wp_' . $this->p . '_' . $name; }
      public function hook(string $name): string { return $this->p . '_' . $name; }
      public function as_group(string $name): string { return $this->p . '-' . $name; }
      public function option(string $name): string { return $this->p . '_' . $name; }
      public function domain_action(string $e): string { return $this->p . '_domain_' . $e; }
      public function integration_action(string $e): string { return $this->p . '_integration_' . $e; }
      public function version(): string { return $this->p; }
    };
  }

  private function make_bracket(string $prefix, bool $audit_enabled = true): CorrelationMiddleware {
    $config = $this->make_config($prefix);
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn($audit_enabled ? $config->table('command_audit') : null);
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('insert')->willReturnCallback(function ($table, $row) {
      $this->inserts[] = $row;
      return true;
    });
    $wpdb->method('update')->willReturnCallback(function ($table, $row) {
      $this->updates[] = $row;
      return true;
    });
    $GLOBALS['wpdb'] = $wpdb;

    $this->uow = new EventsUnitOfWork();
    return new CorrelationMiddleware($config, $this->uow, new Redactor());
  }

  public function test_dispatch_opens_an_act_scope(): void {
    $bracket = $this->make_bracket('actb1');

    $seen = $bracket->execute(new \stdClass(), static function () {
      return [
        'facade_cause' => Correlation::current()->cause,
        'facade_story' => Correlation::current()->correlation_id,
      ];
    });

    $this->assertSame(Kind::Act, $seen['facade_cause']->kind);
    $this->assertSame('stdClass', $seen['facade_cause']->label);

    $this->assertNull(Correlation::peek(), 'scope closed on exit');
  }

  public function test_flat_dispatch_roots_a_fresh_story(): void {
    // No ambient scope: the command is the root of its own story — the
    // audit row gets a minted correlation and NO causation, and the mint
    // never persists into the worker's ambient.
    $bracket = $this->make_bracket('actb2');
    $bracket->execute(new \stdClass(), static fn () => 'ok');

    $this->assertCount(1, $this->inserts);
    $row = $this->inserts[0];
    $this->assertNotEmpty($row['correlation_id'], 'a root still belongs to a story');
    $this->assertNull($row['causation_id'], 'roots have no parent');
    $this->assertNull(Correlation::peek(), 'the mint did not leak into the ambient');
  }

  public function test_facade_scope_parents_the_command(): void {
    $bracket = $this->make_bracket('actb3');

    Correlation::within(Correlation::current()->for_fact('evt-9'), function () use ($bracket) {
      $bracket->execute(new \stdClass(), static fn () => 'ok');
    });

    $row = $this->inserts[0];
    $this->assertSame('evt-9', $row['causation_id']);
    $this->assertSame('integration_event', $row['causation_type']);
    $this->assertSame(Correlation::current()->correlation_id, $row['correlation_id']);
  }

  public function test_nested_dispatch_throws_with_the_label(): void {
    $bracket = $this->make_bracket('actb4');

    try {
      $bracket->execute(new \stdClass(), static function () use ($bracket) {
        $bracket->execute(new \DateTime(), static fn () => 'inner');
      });
      $this->fail('acts never nest');
    } catch (CommandDispatchedInsideCommand $e) {
      $this->assertStringContainsString('stdClass', $e->getMessage(), 'names the enclosing party');
      $this->assertStringContainsString('DateTime', $e->getMessage());
    }

    $this->assertNull(Correlation::current()->cause, 'unwound');
  }

  public function test_audit_disabled_still_brackets_and_guards(): void {
    $bracket = $this->make_bracket('actb5', audit_enabled: false);

    $bracket->execute(new \stdClass(), static function () {
      TestCase::assertSame(Kind::Act, Correlation::current()->cause->kind);
    });

    $this->assertSame([], $this->inserts, 'no audit row');
    $this->assertNull(Correlation::current()->cause, 'restored');
  }

  public function test_error_path_finalises_and_rethrows(): void {
    $bracket = $this->make_bracket('actb6');

    try {
      $bracket->execute(new \stdClass(), static function (): void {
        throw new \RuntimeException('handler blew up');
      });
      $this->fail('must rethrow');
    } catch (\RuntimeException) {
    }

    $this->assertCount(1, $this->updates);
    $this->assertSame('error', $this->updates[0]['status']);
  }

  public function test_redaction_and_events_ride_the_bracket(): void {
    // The two audit behaviors that lived only in the old middleware's tests:
    // sensitive ctor properties masked; published events named in finalise.
    $bracket = $this->make_bracket('actb8');

    $command = new class {
      public string $user = 'u1';
      public string $password = 'hunter2';
    };
    $bracket->execute($command, static fn () => 'ok');

    $params = $this->inserts[0]['parameters'];
    $this->assertStringNotContainsString(
      'hunter2',
      is_string($params) ? $params : json_encode($params),
      'sensitive properties are redacted in the audit row'
    );
    $this->assertArrayHasKey('events', $this->updates[0]);
  }

  public function test_events_entries_carry_their_reactions(): void {
    // The finalise write shapes each published moment as {name, reactions}:
    // reactions recorded during dispatch land on the SAME instances that
    // published() holds, so the WeakMap lookup at finalise time hits. A
    // moment nobody reacted to still carries reactions: [] — additive shape,
    // old rows without the key stay valid.
    $bracket = $this->make_bracket('actb9');

    $bracket->execute(new \stdClass(), function () {
      $reacted = new \TangibleDDD\Tests\Fakes\FakeDomainEvent(1, 'created');
      $ignored = new \TangibleDDD\Tests\Fakes\FakeDomainEvent(2, 'updated');
      $this->uow->record($reacted);
      $this->uow->record($ignored);
      $this->uow->drain();

      // Model the dispatcher's bracket around the reacted-to moment.
      Reactions::open($reacted);
      Reactions::record('Acme\\Handlers\\SendWelcome', 7);
      Reactions::close();

      return 'ok';
    });

    $events = json_decode($this->updates[0]['events'], true);
    $this->assertSame([
      'moments' => [
        [
          'name' => 'fake_domain_event',
          'reactions' => [['handler' => 'Acme\\Handlers\\SendWelcome', 'duration_ms' => 7]],
          'origin' => 'act',
        ],
        [
          'name' => 'fake_domain_event',
          'reactions' => [],
          'origin' => 'act',
        ],
      ],
      'facts' => [],
    ], $events);
  }

  public function test_facts_ride_the_finalise_alongside_moments(): void {
    // The facts roster (hardening item 2): every integration fact published
    // from inside this act lands in the same audit JSON as the moments —
    // {moments: [...], facts: [{name, event_id, announced_by}]}. A direct
    // bus publish has no routing moment, so announced_by is null.
    $bracket = $this->make_bracket('actb10');
    $bus = new \TangibleDDD\Infra\Services\OutboxIntegrationEventBus(
      new \TangibleDDD\Tests\Fakes\FakeOutboxRepository(),
      new \TangibleDDD\Tests\Fakes\FakeDDDConfig()
    );

    $bracket->execute(new \stdClass(), static function () use ($bus) {
      $bus->publish(new \TangibleDDD\Tests\Fakes\FakeIntegrationEvent(entity_id: 7));
      return 'ok';
    });

    $events = json_decode($this->updates[0]['events'], true);
    $this->assertSame(
      [['name' => 'fake_integration_event', 'event_id' => 'evt_1', 'announced_by' => null]],
      $events['facts']
    );
  }

  public function test_stray_facts_from_a_previous_context_never_leak_into_an_act(): void {
    // Per-act reset at bracket-open: whatever a prior (audit-disabled or
    // crashed) context left on the whiteboard is wiped before the handler.
    \TangibleDDD\Application\Events\ActFacts::note('stray_fact', 'evt-stale', null);

    $bracket = $this->make_bracket('actb11');
    $bracket->execute(new \stdClass(), static fn () => 'ok');

    $events = json_decode($this->updates[0]['events'], true);
    $this->assertSame([], $events['facts']);
  }

  public function test_moments_carry_their_origin(): void {
    // Item 5: aggregate-harvested moments say 'aggregate'; handler/act-level
    // record() says 'act' — the fitness signal for handler-raise review.
    $bracket = $this->make_bracket('actb12');

    $bracket->execute(new \stdClass(), function () {
      $aggregate = new class(null) extends \TangibleDDD\Domain\Shared\Aggregate {};
      $aggregate->event(new \TangibleDDD\Tests\Fakes\FakeDomainEvent(1, 'grown'));
      $this->uow->collect_from($aggregate);
      $this->uow->record(new \TangibleDDD\Tests\Fakes\FakeDomainEvent(2, 'rescheduled'));
      $this->uow->drain();
      return 'ok';
    });

    $moments = json_decode($this->updates[0]['events'], true)['moments'];
    $this->assertSame('aggregate', $moments[0]['origin']);
    $this->assertSame('act', $moments[1]['origin']);
  }
}
