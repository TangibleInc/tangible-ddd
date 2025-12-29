<?php

namespace TangibleDDD\WordPress\DI;

use League\Tactician\Handler\Mapping\MapByNamingConvention\ClassName\ClassNameInflector;

/**
 * Tactician naming convention mapper.
 *
 * Mirrors the convention used in tangible-cred:
 * - \Application\...\FooCommands\BarCommand  -> \Application\...\FooCommandHandlers\BarHandler
 * - \Application\...\FooQueries\BazQuery     -> \Application\...\FooQueryHandlers\BazHandler
 *
 * Consumer can customize the convention by subclassing or copying.
 */
class HandlerClassNameInflector implements ClassNameInflector {

  private array $command_values = [ 'singular' => 'Command', 'plural' => 'Commands' ];
  private array $query_values = [ 'singular' => 'Query', 'plural' => 'Queries' ];

  private function isCommand(string $class_name): bool {
    return str_contains($class_name, $this->command_values['plural']);
  }

  private function isQuery(string $class_name): bool {
    return str_contains($class_name, $this->query_values['plural']);
  }

  public function getClassName(string $commandClassName): string {
    if ($this->isCommand($commandClassName)) {
      $values = $this->command_values;
    } elseif ($this->isQuery($commandClassName)) {
      $values = $this->query_values;
    } else {
      throw new \LogicException('Command/Query bus expects either a Command or a Query class name');
    }

    $handler_name = str_replace($values['plural'], "{$values['singular']}Handlers", $commandClassName);
    $handler_name = substr_replace($handler_name, 'Handler', strrpos($handler_name, $values['singular']));

    return $handler_name;
  }
}


