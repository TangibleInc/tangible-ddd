<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\Codec\BlobAttached;
use TangibleDDD\Conformance\Fixtures\Codec\RawBlobAttached;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Codec\LargeString;
use TangibleDDD\Runtime\Codec\PayloadTooLarge;
use TangibleDDD\Runtime\Codec\UnencodablePayload;
use TangibleDDD\Runtime\Delivery\Subscriber;

/**
 * D6 large scalar strings (register section 4 `codec.large-payload`, 3.8
 * codec). HostFixture only: the fact goes through the host bus, outbox,
 * relay, transport and delivery runner, so every host encoding (JSON
 * columns, Messenger serialisation, wp_options-free tables) is crossed.
 *
 * The outbox cap is the core default, OutboxConfig::DEFAULT_MAX_PAYLOAD_BYTES
 * (8 MiB of encoded payload); a host that configures another cap overrides
 * outboxPayloadCap().
 */
abstract class CodecScenarios extends ConformanceTestCase {

  private const MIB = 1024 * 1024;

  #[Group('codec.large-payload')]
  #[TestDox('codec.large-payload: a 1 MB binary field round-trips via LargeString; over its cap, over the outbox cap, or as a raw binary string it fails before commit')]
  public function test_codec_large_payload(): void {
    $bytes = self::binary(self::MIB);
    $received = [];
    $this->host->subscriptions()->add(new Subscriber('conformance.blob', Subscriber::LISTENER, BlobAttached::class,
      static function (IIntegrationEvent $e) use (&$received): void {
        /** @var BlobAttached $e */
        $received[$e->widget_id] = $e->blob?->value;
      }));

    // 1. Round trip: bus → outbox → relay → transport → delivery.
    self::assertNull($this->commit('w-1', static fn () => new BlobAttached('w-1', new LargeString($bytes))));
    self::assertTrue($this->host->scenarioRows()->has('widget:w-1'));
    self::assertSame(1, $this->host->outboxAdministration()->stats()['pending']);
    $report = $this->host->relayOnce();
    self::assertCount(1, $report->accepted, 'relayed');
    $outcomes = $this->host->deliverTransported(BlobAttached::class);
    self::assertCount(1, $outcomes);
    self::assertTrue($outcomes[0]->isComplete());
    self::assertArrayHasKey('w-1', $received);
    self::assertSame(self::MIB, strlen((string) $received['w-1']));
    self::assertSame(hash('sha256', $bytes), hash('sha256', (string) $received['w-1']), 'byte-identical after the round trip');

    // 2. Over the LargeString's declared cap: refused while staging.
    $e = $this->commit('w-2', static fn () => new BlobAttached('w-2', new LargeString($bytes, maxBytes: self::MIB / 2)));
    self::assertInstanceOf(PayloadTooLarge::class, $e);
    $this->assertNothingCommitted('w-2');

    // 3. Over the outbox payload cap: refused at append, before commit.
    $cap = $this->outboxPayloadCap();
    $huge = self::binary($cap);
    $e = $this->commit('w-3', static fn () => new BlobAttached('w-3', new LargeString($huge, maxBytes: 2 * $cap)));
    self::assertInstanceOf(PayloadTooLarge::class, $e, 'the encoded payload exceeds the outbox cap');
    self::assertGreaterThan($e->maxBytes, $e->bytes);
    $this->assertNothingCommitted('w-3');
    unset($huge);

    // 4. Binary in a plain string field: refused at append, pointing at LargeString.
    $e = $this->commit('w-4', static fn () => new RawBlobAttached('w-4', "\xff\xfe\x00binary"));
    self::assertInstanceOf(UnencodablePayload::class, $e);
    self::assertStringContainsString('LargeString', $e->getMessage());
    $this->assertNothingCommitted('w-4');

    $stats = $this->host->outboxAdministration()->stats();
    self::assertSame(1, $stats['accepted']);
    self::assertSame(0, $stats['pending']);
    self::assertSame(0, $stats['dlq']);
  }

  /** The host's OutboxConfig::$max_payload_bytes. */
  protected function outboxPayloadCap(): int {
    return OutboxConfig::DEFAULT_MAX_PAYLOAD_BYTES;
  }

  /**
   * Dispatch CreateWidget($widgetId) on the host bus; its handler inserts
   * the domain row `widget:{id}`, then builds and records the fact.
   * Returns what the bus threw, or null.
   *
   * @param \Closure(): DomainEvent $fact
   */
  private function commit(string $widgetId, \Closure $fact): ?\Throwable {
    $bus = $this->host->commandBus([CreateWidget::class => function (CreateWidget $c) use ($fact): void {
      $this->host->scenarioRows()->insert("widget:{$c->widget_id}", 'created');
      $this->host->events()->record($fact());
    }]);
    return self::catchThrowable(static fn () => $bus->handle(new CreateWidget($widgetId)));
  }

  private function assertNothingCommitted(string $widgetId): void {
    self::assertFalse($this->host->scenarioRows()->has("widget:$widgetId"), "$widgetId: the domain row rolled back");
    self::assertSame(0, $this->host->outboxAdministration()->stats()['pending'], "$widgetId: no outbox row");
  }

  /** $length bytes cycling through 0x00-0xFF (NUL bytes and invalid UTF-8 included). */
  private static function binary(int $length): string {
    $cycle = implode('', array_map('chr', range(0, 255)));
    return substr(str_repeat($cycle, intdiv($length, 256) + 1), 0, $length);
  }
}
