<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Persistence;

use Doctrine\DBAL\Connection;

/** A PRIVATE app service on the ddd connection (E S2: handlers must still reach it). */
final class WidgetRepository {

  public function __construct(private readonly Connection $connection) {}

  public function insert(string $id, string $name): void {
    $this->connection->insert('app_widgets', ['id' => $id, 'name' => $name]);
  }

  public function rename(string $id, string $name): int {
    return (int) $this->connection->update('app_widgets', ['name' => $name], ['id' => $id]);
  }

  public function recordListenerRun(string $listener, string $widgetId, ?string $causeId): void {
    $this->connection->insert('app_listener_runs', ['listener' => $listener, 'widget_id' => $widgetId, 'cause_id' => $causeId]);
  }
}
