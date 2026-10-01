<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Runtime\Audit\AttributeAuditPolicy;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\HeartbeatCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\QuoteToyCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RenameWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\ReportProgressCommand;
use TangibleDDD\Testing\InMemoryAuditSink;

/**
 * D12 on sf (CR-W4CE-3): the bundle's `tangible_ddd.audit.policy` is core
 * AttributeAuditPolicy, so `#[Audit(false)]` / `#[Audit(parameters: false)]`
 * are honoured, and `audit.not_audited` / `audit.without_parameters` feed its
 * two class lists (a parent class or marker interface covers a family).
 */
final class AuditPolicyWiringTest extends KernelTestBase {

  protected static string $variant = 'audit';

  private function sink(): InMemoryAuditSink {
    return self::getContainer()->get('test.audit_sink');
  }

  /** @return array<string, array<string, mixed>> command name => parameters of each opened row */
  private function opened(): array {
    $rows = [];
    foreach ($this->sink()->opened as $open) {
      $rows[$open->command_name] = $open->parameters;
    }
    return $rows;
  }

  public function test_the_bundle_policy_is_the_core_attribute_policy(): void {
    self::assertInstanceOf(AttributeAuditPolicy::class, self::getContainer()->get('tangible_ddd.audit.policy'));
  }

  public function test_attribute_and_configured_lists_decide_what_is_audited(): void {
    (new RegisterWidgetCommand('w1', 'first'))->send();
    self::assertSame('beat', (new HeartbeatCommand('w-7'))->send());
    self::assertSame(['renamed' => 1], (new RenameWidgetCommand('w1', 'second'))->send());
    self::assertSame(40, (new ReportProgressCommand(40))->send());
    (new QuoteToyCommand('t-1'))->send();

    $opened = $this->opened();
    self::assertCount(3, $this->sink()->opened, 'heartbeat (attribute) and rename (not_audited) leave no row: ' . implode(', ', array_keys($opened)));
    self::assertCount(3, $this->sink()->closed);

    $names = array_keys($opened);
    self::assertSame([], array_values(array_filter($names, static fn ($n) => str_contains($n, 'heartbeat') || str_contains($n, 'Heartbeat') || str_contains($n, 'rename') || str_contains($n, 'Rename'))));

    $progress = array_values(array_filter($names, static fn ($n) => stripos($n, 'progress') !== false));
    self::assertCount(1, $progress, 'the progress command is audited');
    self::assertSame([], $opened[$progress[0]], 'without parameters: matched by its marker interface (audit.without_parameters)');

    $register = array_values(array_filter($names, static fn ($n) => stripos($n, 'register') !== false));
    self::assertCount(1, $register);
    self::assertNotSame([], $opened[$register[0]], 'an unlisted command keeps its parameters');
  }
}
