<?php

namespace TangibleDDD\Application\CQRS;

use League\Tactician\Handler\Mapping\ClassName\ClassNameInflector;

/**
 * The bus naming convention, shipped once (0.2.5c):
 *
 *   ...\Commands\IssueEarningCommand  →  ...\CommandHandlers\IssueEarningHandler
 *   ...\Queries\GetThingQuery         →  ...\QueryHandlers\GetThingHandler
 *
 * Byte-compatible with the per-consumer stamped copies it replaces — the
 * convention never contained consumer knowledge, only string surgery.
 * Consumers bind `tactician.class_name_inflector` to this class in their
 * tactician.yaml instead of stamping a copy.
 */
class HandlerClassNameInflector implements ClassNameInflector {

  private array $command_values = ['singular' => 'Command', 'plural' => 'Commands'];
  private array $query_values   = ['singular' => 'Query', 'plural' => 'Queries'];

  private function isCommand(string $commandClassName): bool {
    return str_contains($commandClassName, $this->command_values['plural']);
  }

  private function isQuery(string $commandClassName): bool {
    return str_contains($commandClassName, $this->query_values['plural']);
  }

  public function getClassName(string $commandClassName): string {
    if ($this->isCommand($commandClassName)) {
      $values = $this->command_values;
    } elseif ($this->isQuery($commandClassName)) {
      $values = $this->query_values;
    } else {
      throw new \LogicException('Command/Query bus expects either a Command or a Query');
    }

    $handler_name = str_replace($values['plural'], "{$values['singular']}Handlers", $commandClassName);
    $handler_name = substr_replace($handler_name, 'Handler', strrpos($handler_name, $values['singular']));

    return $handler_name;
  }
}
