<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourTypes;
use TangibleDDD\Domain\ValueObjects\Behaviours\IBehaviourTypes;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Symfony\Tests\Kernel\App\Workflows\DigestStepConfig;
use TangibleDDD\Symfony\Tests\Support\Fixtures\StopBehaviourConfig;

/**
 * W2 (TXP process-kernel demands): behaviour config types are registered at
 * kernel boot, from autoconfigured BaseBehaviourConfig subclasses (the app's
 * resource loading) and from `tangible_ddd.workflow.behaviour_types`, so a
 * stored workflow can be read before any workflow handler was built (a
 * repair command, the operator view, a continuation).
 */
final class BehaviourTypesTest extends KernelTestBase {

  public function test_types_are_registered_at_boot(): void {
    self::assertSame(DigestStepConfig::class, BaseBehaviourConfig::class_for_type('sfk_digest_step'), 'autoconfigured');
    self::assertSame(StopBehaviourConfig::class, BaseBehaviourConfig::class_for_type('sf_stop'), 'listed in config');
    self::assertSame(['sf_stop' => StopBehaviourConfig::class, 'sfk_digest_step' => DigestStepConfig::class],
      self::getContainer()->getParameter('tangible_ddd.behaviour_types'));
  }

  public function test_the_registry_is_one_service_provided_to_core(): void {
    $types = self::getContainer()->get('tangible_ddd.behaviour_types');

    self::assertInstanceOf(BehaviourTypes::class, $types);
    self::assertSame($types, self::getContainer()->get(IBehaviourTypes::class));
    self::assertSame($types, HostDefaults::get(IBehaviourTypes::class), 'provided at boot (CR-W5CC-4)');
    self::assertSame(DigestStepConfig::class, $types->find('sfk_digest_step'));
    self::assertSame(StopBehaviourConfig::class, $types->find('sf_stop'));
  }

  /** bootKernel() boots the previous kernel once more to shut it down, so this also covers a reboot. */
  public function test_include_time_registrations_are_handed_over_and_survive_a_reboot(): void {
    self::ensureKernelShutdown();
    \TangibleDDD\Runtime\HostDefaults::reset_for_tests();
    BaseBehaviourConfig::reset_types_for_tests();
    BaseBehaviourConfig::register_type('sf_early', StopBehaviourConfig::class); // e.g. a handler constructor's register()
    try {
      $kernel = self::bootKernel(['variant' => static::$variant]);

      $types = $kernel->getContainer()->get('tangible_ddd.behaviour_types');
      self::assertSame(StopBehaviourConfig::class, $types->find('sf_early'), 'the fallback was handed over');
      self::assertSame(DigestStepConfig::class, $types->find('sfk_digest_step'), 'the compiled types are kept');
      self::assertSame(StopBehaviourConfig::class, BaseBehaviourConfig::class_for_type('sf_early'));
    } finally {
      BaseBehaviourConfig::reset_types_for_tests();
    }
  }

  public function test_a_stored_workflow_reads_back_without_its_handler(): void {
    $repository = self::getContainer()->get('test.d10_stores')[0];
    $workflow = new BehaviourWorkflow(null, 1, 'digest', [new DigestStepConfig(25)]);
    $repository->save($workflow);

    $read = $repository->get_by_id((int) $workflow->get_id());
    self::assertInstanceOf(DigestStepConfig::class, $read->get_current());
    self::assertSame(25, $read->get_current()->size);
  }
}
