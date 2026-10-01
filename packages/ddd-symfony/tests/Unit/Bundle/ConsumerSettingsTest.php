<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TangibleDDD\Symfony\Bundle\ConsumerSettings;
use TangibleDDD\Symfony\Bundle\TangibleDddBundle;
use TangibleDDD\Symfony\DependencyInjection\Compiler\SubscriptionMapPass;

/** Wave 5: `consumer:` (shorthand) and `consumers:` resolved to per-consumer settings. */
final class ConsumerSettingsTest extends TestCase {

  /** @param array<string, mixed> $tangible */
  private static function config(array $tangible): array {
    $bundle = new TangibleDddBundle();
    $extension = $bundle->getContainerExtension();
    assert($extension !== null);
    return (new Processor())->processConfiguration($extension->getConfiguration([], new ContainerBuilder()), [$tangible]);
  }

  public function test_the_shorthand_is_one_primary_consumer_with_todays_ids_and_tables(): void {
    [$c] = ConsumerSettings::resolve(self::config([
      'consumer' => ['prefix' => 'txp', 'namespace_root' => 'App'],
      'table_prefix' => 'x_',
    ]));

    self::assertTrue($c['primary']);
    self::assertSame('txp', $c['prefix']);
    self::assertSame('App', $c['namespace_root']);
    self::assertSame('x_', $c['tables']);
    self::assertSame('doctrine.dbal.default_connection', $c['connection_service']);
    self::assertSame(['ddd_facts', 'ddd_wakeups'], [$c['transport'], $c['wakeup_transport']]);
    self::assertSame('tangible_ddd.outbox_store', ConsumerSettings::id($c, 'outbox_store'));
  }

  public function test_a_map_resolves_defaults_per_consumer(): void {
    [$app, $billing] = ConsumerSettings::resolve(self::config([
      'consumers' => [
        'txp' => ['namespace_root' => 'App\\'],
        'billing' => ['prefix' => 'bil', 'bundle' => 'Acme\\Billing\\AcmeBillingBundle', 'schema' => 'billing', 'connection' => 'billing', 'delivery' => ['budget' => 3]],
      ],
    ]));

    self::assertSame(['txp', 'App', ''], [$app['prefix'], $app['namespace_root'], $app['tables']]);
    self::assertSame('bil', $billing['prefix']);
    self::assertSame('Acme\\Billing', $billing['namespace_root'], 'the namespace of the bundle');
    self::assertSame('billing.', $billing['tables']);
    self::assertSame('doctrine.dbal.billing_connection', $billing['connection_service']);
    self::assertSame(['ddd_facts_bil', 'ddd_wakeups_bil'], [$billing['transport'], $billing['wakeup_transport']]);
    self::assertSame(3, $billing['delivery']['budget']);
    self::assertSame(5, $app['delivery']['budget']);
    self::assertSame('tangible_ddd.consumer.billing.outbox_store', ConsumerSettings::id($billing, 'outbox_store'));
  }

  public function test_ownership_is_the_longest_namespace_root(): void {
    $consumers = [['namespace_root' => 'App'], ['namespace_root' => 'App\\Billing'], ['namespace_root' => 'Acme']];

    self::assertSame(1, SubscriptionMapPass::owner('App\\Billing\\Listeners\\X', $consumers));
    self::assertSame(0, SubscriptionMapPass::owner('App\\Billingual\\X', $consumers), 'whole segments only');
    self::assertSame(2, SubscriptionMapPass::owner('Acme\\Y', $consumers));
    self::assertSame(0, SubscriptionMapPass::owner('Elsewhere\\Z', $consumers), 'outside every root: the primary consumer');
  }

  /** @return iterable<string, array{array<string, mixed>, string}> */
  public static function refused(): iterable {
    yield 'neither' => [[], 'either `consumer`'];
    yield 'both' => [['consumer' => ['prefix' => 'a', 'namespace_root' => 'A'], 'consumers' => ['b' => ['namespace_root' => 'B']]], 'not both'];
    yield 'no root' => [['consumers' => ['a' => []]], 'namespace_root'];
    yield 'same prefix' => [['consumers' => ['a' => ['namespace_root' => 'A'], 'b' => ['prefix' => 'a', 'namespace_root' => 'B']]], 'same prefix'];
    yield 'same root' => [['consumers' => ['a' => ['namespace_root' => 'A'], 'b' => ['namespace_root' => 'A']]], 'same namespace_root'];
    yield 'shared tables' => [['consumers' => ['a' => ['namespace_root' => 'A'], 'b' => ['namespace_root' => 'B', 'table_prefix' => '']]], 'share their tables'];
    yield 'shared transport, other budget' => [['consumers' => [
      'a' => ['namespace_root' => 'A'],
      'b' => ['namespace_root' => 'B', 'schema' => 'b', 'transport' => 'ddd_facts', 'delivery' => ['budget' => 2]],
    ]], 'different delivery settings'];
  }

  /** @param array<string, mixed> $tangible */
  #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
  public function test_refuses_ambiguous_configuration(array $tangible, string $message): void {
    $this->expectException(InvalidConfigurationException::class);
    $this->expectExceptionMessage($message);
    ConsumerSettings::resolve(self::config($tangible));
  }
}
