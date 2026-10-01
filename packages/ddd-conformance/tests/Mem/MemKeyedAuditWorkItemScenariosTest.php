<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\AuditEntry;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\WorkItemScenarios;

/**
 * Not a host result: pins that workflow.item-deterministic-id holds on a
 * host whose audit store keeps one row per command id (HC5-2; the wp 0.6
 * {prefix}_command_audit table, UNIQUE command_id). A re-run under the
 * same id adds no row there, the last close wins.
 */
#[Group('mem')]
final class MemKeyedAuditWorkItemScenariosTest extends WorkItemScenarios {

  protected function create_fixture(): HostFixture {
    return new class extends MemHostFixture {

      public function audit_trail(): array {
        $rows = [];
        foreach (parent::audit_trail() as $entry) {
          /** @var AuditEntry $entry */
          $rows[$entry->command_id] = $entry;
        }
        return array_values($rows);
      }
    };
  }
}
