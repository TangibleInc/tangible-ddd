<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
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

  public function test_a_stored_workflow_reads_back_without_its_handler(): void {
    $repository = self::getContainer()->get('test.d10_stores')[0];
    $workflow = new BehaviourWorkflow(null, 1, 'digest', [new DigestStepConfig(25)]);
    $repository->save($workflow);

    $read = $repository->get_by_id((int) $workflow->get_id());
    self::assertInstanceOf(DigestStepConfig::class, $read->get_current());
    self::assertSame(25, $read->get_current()->size);
  }
}
