<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\BehaviourWorkflows;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\MemWorkflowRepository;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourTypes;
use TangibleDDD\Domain\ValueObjects\Behaviours\IBehaviourTypes;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\Ids\NameBasedUuid;

require_once dirname(__DIR__) . '/Fixtures/Workflow/WorkflowFixtures.php';

final class GrantConfig extends BaseBehaviourConfig {
  public function get_behaviour_type(): string { return 'w5_grant'; }

  protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static {
    return new static();
  }
}

final class OtherGrantConfig extends BaseBehaviourConfig {
  public function get_behaviour_type(): string { return 'w5_grant'; }

  protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static {
    return new static();
  }
}

/** A work-item ledger in memory (the ledger port). */
final class MemItems implements IWorkItemRepository {

  /** @var array<int, WorkItem> */
  public array $rows = [];
  private int $next = 1;

  public function get_by_id(int $id): WorkItem {
    return $this->rows[$id] ?? throw new \RuntimeException("no item $id");
  }

  public function find_by_unique(int $workflow_id, int $behaviour_idx, int $phase, string $item_key): ?WorkItem {
    foreach ($this->rows as $item) {
      if ([$item->workflow_id, $item->behaviour_idx, $item->phase, $item->item_key] === [$workflow_id, $behaviour_idx, $phase, $item_key]) {
        return $item;
      }
    }
    return null;
  }

  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList {
    return new WorkItemList(array_values(array_filter(
      $this->rows,
      static fn (WorkItem $i) => [$i->workflow_id, $i->behaviour_idx, $i->phase] === [$workflow_id, $behaviour_idx, $phase],
    )));
  }

  public function save(WorkItem $item): void {
    if ($item->get_id() === null) {
      $item->set_id($this->next++);
    }
    $this->rows[$item->get_id()] = $item;
  }
}

/** Each item dispatches one command; the id its act bracket would take is recorded. */
final class GrantWorkflow extends WorkflowHandler {

  /** @var array<string, list<?string>> item key → command ids seen, one per run */
  public array $ids = [];

  /**
   * Keys whose first run dies after the item's command committed and before
   * the ledger saved the item (the worker crashed in between).
   *
   * @var list<string>
   */
  public array $crash_once = [];

  protected function get_workflows(ICommand $command): array { return []; }

  public function run(BehaviourWorkflow $workflow): void {
    $this->handle_workflow($workflow);
  }

  protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult {
    $this->ids[$item->item_key][] = DeterministicCommandId::take();
    if (in_array($item->item_key, $this->crash_once, true) && count($this->ids[$item->item_key]) === 1) {
      throw new \RuntimeException('worker died');
    }
    return new BehaviourExecutionResult(
      type: $config->get_behaviour_type(),
      success: true,
      context: ['message' => 'ok'],
      status: BehaviourExecutionStatus::completed,
      timestamp: gmdate('c'),
      phase: 1,
    );
  }

  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
    return new WorkItemList([
      new WorkItem(null, 0, 0, 1, 'user:1'),
      new WorkItem(null, 0, 0, 1, 'user:2'),
    ]);
  }

  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
}

/**
 * Wave 5, TXP workflow demands W4 (a deterministic id for work-item
 * commands) and W2 (behaviour config types behind an injectable registry
 * port, the static facade delegating to it).
 */
final class WorkflowItemsAndTypesTest extends TestCase {

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    DeterministicCommandId::take();
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
  }

  // ── W4 ────────────────────────────────────────────────────────────────────

  public function test_for_item_is_deterministic_and_distinct_per_coordinate(): void {
    $id = DeterministicCommandId::for_item('acme', 7, 0, 1, 'user:1');

    self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id, 'the act bracket spelling');
    self::assertSame($id, DeterministicCommandId::for_item('acme', 7, 0, 1, 'user:1'));
    $workflow = NameBasedUuid::v5(DeterministicCommandId::WORKFLOW_NAMESPACE, 'acme:7');
    self::assertSame(str_replace('-', '', NameBasedUuid::v5($workflow, 'item:0:1:0:user:1')), $id, 'the documented derivation');

    $others = [
      DeterministicCommandId::for_item('other', 7, 0, 1, 'user:1'),
      DeterministicCommandId::for_item('acme', 8, 0, 1, 'user:1'),
      DeterministicCommandId::for_item('acme', 7, 1, 1, 'user:1'),
      DeterministicCommandId::for_item('acme', 7, 0, 2, 'user:1'),
      DeterministicCommandId::for_item('acme', 7, 0, 1, 'user:2'),
      DeterministicCommandId::for_item('acme', 7, 0, 1, 'user:1', 1),
      DeterministicCommandId::for_step('acme', 7, 0, 0),
    ];
    self::assertNotContains($id, $others);
    self::assertCount(count($others), array_unique($others));
  }

  public function test_the_handler_dispatches_each_items_command_under_its_item_id_and_a_rerun_repeats_it(): void {
    $workflows = new MemWorkflowRepository();
    $items = new MemItems();
    $handler = new GrantWorkflow($workflows, $items, new AcmeConfig());
    $handler->crash_once = ['user:2'];
    $workflow = new BehaviourWorkflow(null, 1, 'grant', [new GrantConfig()]);

    try {
      $handler->run($workflow);
      self::fail('expected the crash');
    } catch (\RuntimeException) {
    }
    $id = (int) $workflow->get_id();
    // The restart (a fact redelivery) finds user:2 still pending in the ledger.
    $handler->run($workflow);

    self::assertSame(
      [DeterministicCommandId::for_item('acme', $id, 0, 1, 'user:1')],
      $handler->ids['user:1'],
    );
    $second = DeterministicCommandId::for_item('acme', $id, 0, 1, 'user:2');
    self::assertSame([$second, $second], $handler->ids['user:2'], 'the re-run dispatches the same id');
    self::assertNull(DeterministicCommandId::peek(), 'the hint never leaks past the item');
  }

  public function test_without_a_config_the_consumer_part_is_empty(): void {
    $handler = new GrantWorkflow(new MemWorkflowRepository(), new MemItems());
    $workflow = new BehaviourWorkflow(null, 1, 'grant', [new GrantConfig()]);

    $handler->run($workflow);

    self::assertSame([DeterministicCommandId::for_item('', (int) $workflow->get_id(), 0, 1, 'user:1')], $handler->ids['user:1']);
  }

  // ── W2 ────────────────────────────────────────────────────────────────────

  public function test_a_host_registry_resolves_types_and_the_static_facade_writes_to_it(): void {
    $types = new BehaviourTypes();
    $types->register('w5_grant', GrantConfig::class);
    HostDefaults::provide(IBehaviourTypes::class, $types);

    self::assertInstanceOf(GrantConfig::class, BaseBehaviourConfig::from_json('{"type":"w5_grant"}'));

    BaseBehaviourConfig::register_type('w5_other', OtherGrantConfig::class);
    self::assertSame(OtherGrantConfig::class, $types->find('w5_other'), 'the 0.6 facade delegates to the host registry');
    self::assertSame(OtherGrantConfig::class, BaseBehaviourConfig::class_for_type('w5_other'));
  }

  public function test_types_registered_before_the_host_registry_stay_resolvable(): void {
    BaseBehaviourConfig::register_type('w5_early', GrantConfig::class);
    HostDefaults::provide(IBehaviourTypes::class, new BehaviourTypes());

    self::assertSame(GrantConfig::class, BaseBehaviourConfig::class_for_type('w5_early'), 'a 0.6 caller that registered at include time');
  }

  public function test_the_host_registry_wins_over_an_early_static_registration(): void {
    BaseBehaviourConfig::register_type('w5_both', GrantConfig::class);
    $types = new BehaviourTypes();
    $types->register('w5_both', OtherGrantConfig::class);
    HostDefaults::provide(IBehaviourTypes::class, $types);

    self::assertSame(OtherGrantConfig::class, BaseBehaviourConfig::class_for_type('w5_both'));
  }

  public function test_an_unknown_type_still_throws_invalid_argument(): void {
    HostDefaults::provide(IBehaviourTypes::class, new BehaviourTypes());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid behaviour type: w5_nope');
    BaseBehaviourConfig::class_for_type('w5_nope');
  }
}
