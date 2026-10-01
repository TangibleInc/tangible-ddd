<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Bundle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TangibleDDD\Symfony\Bundle\TangibleDddBundle;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;

/** L4: an absent, null or empty consumer.version is '0.0.0', at config processing and at runtime (env placeholders). */
final class ConsumerVersionTest extends TestCase {

  /** @return iterable<string, array{mixed, string}> */
  public static function versions(): iterable {
    yield 'absent' => [false, '0.0.0'];
    yield 'null' => [null, '0.0.0'];
    yield 'empty' => ['', '0.0.0'];
    yield 'given' => ['1.4.2', '1.4.2'];
  }

  #[DataProvider('versions')]
  public function test_config_processing_normalises_the_version(mixed $version, string $expected): void {
    $consumer = ['prefix' => 'txp', 'namespace_root' => 'App'];
    if ($version !== false) {
      $consumer['version'] = $version;
    }
    $extension = (new TangibleDddBundle())->getContainerExtension();
    $config = (new Processor())->processConfiguration(
      $extension->getConfiguration([], new ContainerBuilder()),
      [['consumer' => $consumer]]
    );

    self::assertSame($expected, $config['consumer']['version']);
  }

  public function test_the_consumer_config_normalises_a_null_resolved_at_runtime(): void {
    // `version: '%env(default::APP_VERSION)%'` resolves to null when APP_VERSION is unset.
    self::assertSame('0.0.0', (new SymfonyConsumerConfig('txp', 'App', null))->version());
    self::assertSame('0.0.0', (new SymfonyConsumerConfig('txp', 'App', ''))->version());
    self::assertSame('2.0.0', (new SymfonyConsumerConfig('txp', 'App', '2.0.0'))->version());
  }
}
