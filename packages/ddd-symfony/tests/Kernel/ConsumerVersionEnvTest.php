<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;

/**
 * L4: `consumer.version: '%env(default::APP_VERSION)%'` with the variable
 * unset boots, registers the consumer as version '0.0.0', and commands run.
 */
final class ConsumerVersionEnvTest extends KernelTestBase {

  protected static string $variant = 'version_env';

  public function test_an_unset_version_env_boots_as_0_0_0(): void {
    self::assertSame('0.0.0', self::getContainer()->get('tangible_ddd.consumer_config')->version());
    self::assertSame('0.0.0', ConsumerRegistry::owner_of(RegisterWidgetCommand::class)->version());
    self::assertSame('0.0.0', self::getContainer()->get('tangible_ddd.audit.environment')->describe()['app']);

    (new RegisterWidgetCommand('w1'))->send();
    self::assertSame(1, $this->countRows('SELECT count(*) FROM app_widgets'));
  }
}
