<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationTranslator;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;

/**
 * The portable listener base (register 1.4): the same protected
 * get_event_class() / get_command() pair as the 0.6 IntegrationListener, plus
 * public final event_class() / translate(), and no constructor side effect,
 * so it constructs and translates with no WordPress loaded.
 */
final class IntegrationTranslatorTest extends TestCase {

  private function translator(): IntegrationTranslator {
    return new class extends IntegrationTranslator {
      protected function get_event_class(): string {
        return UserJoined::class;
      }

      protected function get_command(IIntegrationEvent $event): ?ICommand {
        /** @var UserJoined $event */
        return $event->user_id > 0 ? new RecordingCommand('welcome', $event->user_id) : null;
      }
    };
  }

  public function test_it_constructs_without_side_effects_and_exposes_the_event_class(): void {
    $this->assertSame(UserJoined::class, $this->translator()->event_class());
  }

  public function test_translate_returns_the_command_or_null(): void {
    $translator = $this->translator();

    $command = $translator->translate(new UserJoined(7));
    $this->assertInstanceOf(RecordingCommand::class, $command);
    $this->assertSame(7, $command->data);

    $this->assertNull($translator->translate(new UserJoined(0)));
  }

  public function test_the_public_pair_is_final_and_the_hooks_stay_protected(): void {
    $ref = new \ReflectionClass(IntegrationTranslator::class);

    $this->assertTrue($ref->isAbstract());
    $this->assertNull($ref->getConstructor(), 'no constructor, so no side effect');
    foreach (['event_class', 'translate'] as $method) {
      $this->assertTrue($ref->getMethod($method)->isPublic(), $method);
      $this->assertTrue($ref->getMethod($method)->isFinal(), $method);
    }
    foreach (['get_event_class', 'get_command'] as $method) {
      $this->assertTrue($ref->getMethod($method)->isProtected(), $method);
      $this->assertTrue($ref->getMethod($method)->isAbstract(), $method);
    }
  }

  public function test_the_registrar_takes_a_translator_through_its_public_pair(): void {
    $registry = new SubscriptionRegistry();
    (new SubscriptionRegistrar($registry))->register_listener($this->translator());

    $this->assertCount(1, $registry->for(UserJoined::class));
  }
}
