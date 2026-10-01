<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;

/** tangible_ddd.transaction.entity_manager: the ORM is flushed inside the command transaction, before COMMIT. */
final class EntityManagerFlushTest extends KernelTestBase {

  protected static string $variant = 'flush';

  public function test_the_configured_entity_manager_is_flushed_before_commit(): void {
    (new RegisterWidgetCommand('w1'))->send();

    self::assertSame([true], self::getContainer()->get('test.flusher')->flushes);
    self::assertSame(1, $this->countRows('SELECT count(*) FROM app_widgets'));
  }
}
