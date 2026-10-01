<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Runtime\Codec\LargeString;
use TangibleDDD\Runtime\Codec\PayloadTooLarge;
use TangibleDDD\Runtime\Codec\UndecodableLargeString;
use TangibleDDD\Runtime\Codec\UnencodablePayload;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;

final class RunnerJobOrdered extends IntegrationEvent {
  public function __construct(
    public readonly string $job_type = 'web.deploy-build',
    public readonly ?LargeString $payload_json = null,
  ) {}

  protected static function prefix(): string {
    return 'acme';
  }
}

final class RawBodyFact extends IntegrationEvent {
  public function __construct(public readonly string $body = '') {}

  protected static function prefix(): string {
    return 'acme';
  }
}

/**
 * D6 / `codec.large-payload` (mem): a large binary scalar round-trips via
 * LargeString, or fails before commit with PayloadTooLarge; a corrupt
 * stored value is undecodable with a quarantine reason.
 */
final class LargeStringCodecTest extends TestCase {

  protected function setUp(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
  }

  private function bus(InMemoryOutboxStore $store, ?OutboxConfig $config = null): OutboxIntegrationEventBus {
    return new OutboxIntegrationEventBus(null, new AcmeConfig(), null, new FrozenClock(new \DateTimeImmutable('2026-10-01T00:00:00Z')), $store, $config ?? new OutboxConfig());
  }

  public function test_a_1_mb_binary_field_round_trips_through_the_outbox_payload(): void {
    $bytes = random_bytes(1024 * 1024);
    $store = new InMemoryOutboxStore(new FrozenClock(new \DateTimeImmutable('2026-10-01T00:00:00Z')));

    $this->bus($store)->publish(new RunnerJobOrdered('web.deploy-build', new LargeString($bytes)));

    $record = $store->recordOf($store->eventIds()[0]);
    $json = json_encode($record->payload, JSON_THROW_ON_ERROR);
    $fact = RunnerJobOrdered::from_payload(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    self::assertSame($bytes, $fact->payload_json->value);
    self::assertSame(1024 * 1024, $fact->payload_json->bytes());
  }

  public function test_null_stays_null(): void {
    $fact = RunnerJobOrdered::from_payload((new RunnerJobOrdered('x', null))->integration_payload());
    self::assertNull($fact->payload_json);
  }

  public function test_a_value_over_its_declared_cap_is_refused_at_construction(): void {
    try {
      new LargeString(str_repeat('a', 11), maxBytes: 10);
      self::fail('expected PayloadTooLarge');
    } catch (PayloadTooLarge $e) {
      self::assertSame(11, $e->bytes);
      self::assertSame(10, $e->maxBytes);
    }
  }

  public function test_a_payload_over_the_outbox_cap_fails_at_append_and_nothing_commits(): void {
    $clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T00:00:00Z'));
    $tx = new InMemoryTransactionBoundary();
    $store = new InMemoryOutboxStore($clock, null, $tx);
    $tx->enlist($store);
    $bus = $this->bus($store, OutboxConfig::from_array(['max_payload_bytes' => 64 * 1024]));

    try {
      $tx->run(static fn () => $bus->publish(new RunnerJobOrdered('x', new LargeString(random_bytes(100 * 1024)))));
      self::fail('expected PayloadTooLarge');
    } catch (PayloadTooLarge $e) {
      self::assertGreaterThan(64 * 1024, $e->bytes);
      self::assertSame(64 * 1024, $e->maxBytes);
      self::assertStringContainsString('runner_job_ordered', $e->getMessage());
    }
    self::assertSame([], $store->eventIds());
  }

  public function test_a_raw_binary_string_field_fails_at_append_pointing_at_large_string(): void {
    $store = new InMemoryOutboxStore(new FrozenClock(new \DateTimeImmutable('2026-10-01T00:00:00Z')));

    $this->expectException(UnencodablePayload::class);
    $this->expectExceptionMessage('LargeString');
    $this->bus($store)->publish(new RawBodyFact("\xff\xfe\x00binary"));
  }

  public function test_a_corrupt_stored_value_is_undecodable_with_a_quarantine_reason(): void {
    $encoded = (new LargeString('hello world'))->toPayload();

    $tampered = $encoded;
    $tampered['data'] = base64_encode('hello w0rld');
    try {
      LargeString::fromPayload($tampered);
      self::fail('expected UndecodableLargeString');
    } catch (UndecodableLargeString $e) {
      self::assertStringContainsString('sha256', $e->quarantineReason);
    }

    foreach ([
      'not an array' => 'plain string',
      'no marker' => ['data' => base64_encode('x')],
      'bad base64' => ['data' => '%%%'] + $encoded,
      'over its cap' => ['max_bytes' => 4] + $encoded,
    ] as $case => $raw) {
      try {
        LargeString::fromPayload($raw);
        self::fail("expected UndecodableLargeString for $case");
      } catch (UndecodableLargeString $e) {
        self::assertNotSame('', $e->quarantineReason, $case);
      }
    }
  }

  public function test_hydrating_a_fact_with_a_corrupt_large_string_throws_the_quarantine_exception(): void {
    $payload = (new RunnerJobOrdered('x', new LargeString('abc')))->integration_payload();
    $payload['payload_json']['bytes'] = 99;

    $this->expectException(UndecodableLargeString::class);
    RunnerJobOrdered::from_payload($payload);
  }
}
