<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\Exceptions\IncorrectUsageException;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;

/** Register 1.4 / X11: OutboxConfig in core; from_options() delegates; from_array() added. */
final class OutboxConfigCoreTest extends TestCase {

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
  }

  public function test_from_array_overrides_named_fields_and_keeps_defaults(): void {
    $config = OutboxConfig::from_array(['batch_size' => 10, 'lock_timeout_seconds' => 120, 'retry_multiplier' => 3]);

    self::assertSame(10, $config->batch_size);
    self::assertSame(120, $config->lock_timeout_seconds);
    self::assertSame(3.0, $config->retry_multiplier);
    self::assertSame(5, $config->max_attempts, 'unnamed fields keep their defaults');
  }

  public function test_from_array_rejects_unknown_keys(): void {
    $this->expectException(\InvalidArgumentException::class);
    OutboxConfig::from_array(['batch' => 10]);
  }

  public function test_from_options_without_a_host_reader_is_an_incorrect_usage(): void {
    $this->expectException(IncorrectUsageException::class);
    OutboxConfig::from_options($this->createStub(IDDDConfig::class));
  }

  public function test_from_options_delegates_to_the_host_reader(): void {
    $expected = new OutboxConfig(batch_size: 7);
    HostDefaults::provide(IOutboxOptionsReader::class, new class($expected) implements IOutboxOptionsReader {
      public function __construct(private OutboxConfig $config) {}
      public function read(IDDDConfig $config): OutboxConfig { return $this->config; }
    });

    self::assertSame($expected, OutboxConfig::from_options($this->createStub(IDDDConfig::class)));
  }
}
