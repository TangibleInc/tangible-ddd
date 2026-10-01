<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Symfony\Persistence\EntityManagerSession;
use TangibleDDD\Symfony\Tests\Support\RecordingLogger;

/** L6: EntityManagerSession without doctrine/orm types (duck-typed manager and registry). */
final class EntityManagerSessionTest extends TestCase {

  public function test_reset_clears_an_open_manager(): void {
    $em = new FakeManager();

    (new EntityManagerSession($em))->reset();

    self::assertSame(1, $em->clears);
  }

  public function test_reset_replaces_a_closed_manager_through_the_registry(): void {
    $em = new FakeManager(open: false);
    $registry = new FakeRegistry(['default' => 'doctrine.orm.default_entity_manager'], ['default' => $em]);

    (new EntityManagerSession($em, $registry, 'doctrine.orm.default_entity_manager'))->reset();

    self::assertSame(['default'], $registry->resets);
    self::assertSame(0, $em->clears);
  }

  public function test_a_manager_configured_by_alias_is_found_by_instance(): void {
    $em = new FakeManager(open: false);
    $registry = new FakeRegistry(['main' => 'doctrine.orm.main_entity_manager'], ['main' => $em]);

    (new EntityManagerSession($em, $registry, 'Doctrine\\ORM\\EntityManagerInterface'))->reset();

    self::assertSame(['main'], $registry->resets);
  }

  public function test_flush_goes_to_the_registry_s_current_manager(): void {
    $old = new FakeManager();
    $new = new FakeManager();
    $registry = new FakeRegistry(['default' => 'em'], ['default' => $old]);
    $session = new EntityManagerSession($old, $registry, 'em');

    $session->flush();
    $registry->managers['default'] = $new; // what resetManager() does for a non-lazy manager
    $session->flush();

    self::assertSame(1, $old->flushes);
    self::assertSame(1, $new->flushes);
  }

  public function test_a_closed_manager_without_a_registry_is_logged(): void {
    $logger = new RecordingLogger();

    (new EntityManagerSession(new FakeManager(open: false), null, 'app.em', $logger))->reset();

    self::assertCount(1, $logger->at('error'));
    self::assertStringContainsString('app.em', $logger->at('error')[0]);
  }

  public function test_a_plain_flusher_is_accepted_and_reset_is_a_no_op(): void {
    $flusher = new class {
      public int $flushes = 0;
      public function flush(): void { $this->flushes++; }
    };
    $session = new EntityManagerSession($flusher);

    $session->flush();
    $session->reset();

    self::assertSame(1, $flusher->flushes);
  }

  public function test_a_service_without_flush_is_refused(): void {
    $this->expectException(\InvalidArgumentException::class);
    new EntityManagerSession(new \stdClass());
  }
}

final class FakeManager {
  public int $flushes = 0;
  public int $clears = 0;

  public function __construct(private bool $open = true) {}

  public function flush(): void { $this->flushes++; }
  public function clear(): void { $this->clears++; }
  public function isOpen(): bool { return $this->open; }
}

final class FakeRegistry {
  /** @var list<string> */
  public array $resets = [];

  /**
   * @param array<string, string> $names
   * @param array<string, object> $managers
   */
  public function __construct(private array $names, public array $managers) {}

  public function getManagerNames(): array { return $this->names; }
  public function getManager(string $name): object { return $this->managers[$name]; }
  public function resetManager(string $name): object {
    $this->resets[] = $name;
    return $this->managers[$name];
  }
}
