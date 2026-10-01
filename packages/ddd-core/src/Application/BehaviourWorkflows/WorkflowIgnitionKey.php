<?php

declare(strict_types=1);

namespace TangibleDDD\Application\BehaviourWorkflows;

use TangibleDDD\Runtime\Ids\NameBasedUuid;

/**
 * Dedup keys for the workflow ignition ledger (D10). The key is the
 * caller's choice; these are the two common shapes.
 */
final class WorkflowIgnitionKey {

  /**
   * One workflow per igniting fact: uuid5(event_id, kind), the same value
   * as ddd-symfony's DbalWorkflowIgnitionLedger::keyForFact().
   */
  public static function forFact(string $eventId, string $kind): string {
    return NameBasedUuid::v5($eventId, $kind);
  }

  /**
   * One workflow per $scope per UTC minute (a cron entry's tick):
   * "{scope}:{Y-m-d\TH:i}Z". Two ticks in one minute share it.
   */
  public static function perMinute(string $scope, \DateTimeImmutable $at): string {
    return $scope . ':' . $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i') . 'Z';
  }
}
