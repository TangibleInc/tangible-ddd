<?php

namespace TangibleDDD\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use TangibleDDD\WordPress\CLI\DDD_Command;

/**
 * Pins the `wp ddd init` templates to the framework that ships them.
 *
 * The scaffolder's output is the first wiring every new consumer runs — and
 * it is dead code to the type system: nothing compiles it until a consumer
 * generates it, so a renamed framework class or a drifted constructor lives
 * in the templates silently. That is not hypothetical: the 0.2.0 release
 * moved OutboxProcessor, and every early consumer shipped ProcessRunner
 * constructor args that never matched any framework version — both traced
 * back to this file's templates.
 *
 * Two checks over the rendered template set:
 *  1. every TangibleDDD class-like reference resolves against ddd-src;
 *  2. every hand-listed positional `arguments:` block in the services yaml
 *     is compatible with the real constructor (exists, arity fits, each
 *     '@service' reference satisfies the parameter type).
 */
final class ScaffoldTemplatesConformanceTest extends TestCase {

  /** @var array<string, string> file => rendered content */
  private static array $templates;

  public static function setUpBeforeClass(): void {
    require_once dirname( __DIR__, 3 ) . '/ddd-wordpress/cli/class-ddd-command.php';

    $command = ( new \ReflectionClass( DDD_Command::class ) )->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod( DDD_Command::class, 'get_templates' );
    $method->setAccessible( true );

    self::$templates = $method->invoke( $command, 'acme_orders', 'AcmeOrders', 'ACME_ORDERS_VERSION' );
  }

  public function test_every_framework_reference_in_the_templates_resolves(): void {
    $missing = [];

    foreach ( self::$templates as $file => $content ) {
      preg_match_all( '/TangibleDDD(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+/', $content, $matches );

      foreach ( array_unique( $matches[0] ) as $raw ) {
        $fqcn = str_replace( '\\\\', '\\', $raw );

        // WordPress-layer function references (register_hooks, install_tables)
        // live outside the psr-4 class map; only class-likes are checkable.
        if ( str_starts_with( $fqcn, 'TangibleDDD\\WordPress\\' ) ) {
          continue;
        }

        if ( ! class_exists( $fqcn ) && ! interface_exists( $fqcn ) && ! trait_exists( $fqcn ) ) {
          $missing[] = "$file → $fqcn";
        }
      }
    }

    $this->assertSame(
      [],
      $missing,
      "Templates reference framework classes that do not exist:\n" . implode( "\n", $missing )
    );
  }

  public function test_hand_listed_service_arguments_match_the_real_constructors(): void {
    $violations = [];

    foreach ( $this->parse_service_definitions( self::$templates['ddd-wordpress/di/services.yaml'] ) as $service ) {
      $class = $service['class'];

      if ( ! class_exists( $class ) ) {
        continue; // covered (and failed) by the reference-resolution test
      }

      $ctor = ( new \ReflectionClass( $class ) )->getConstructor();
      $args = $service['arguments'];

      if ( $ctor === null ) {
        $violations[] = "$class: {" . count( $args ) . '} arguments listed but the class has no constructor (PHP discards them silently)';
        continue;
      }

      $params = $ctor->getParameters();
      $required = $ctor->getNumberOfRequiredParameters();

      if ( count( $args ) < $required || count( $args ) > count( $params ) ) {
        $violations[] = sprintf(
          '%s: %d arguments listed, constructor wants %d–%d',
          $class,
          count( $args ),
          $required,
          count( $params )
        );
        continue;
      }

      foreach ( $args as $i => $arg_type ) {
        $param_type = $params[ $i ]->getType();
        if ( ! $param_type instanceof \ReflectionNamedType || $param_type->isBuiltin() ) {
          continue;
        }
        if ( ! is_a( $arg_type, $param_type->getName(), true ) ) {
          $violations[] = sprintf(
            '%s: argument %d is @%s but parameter $%s wants %s',
            $class,
            $i,
            $arg_type,
            $params[ $i ]->getName(),
            $param_type->getName()
          );
        }
      }
    }

    $this->assertSame(
      [],
      $violations,
      "Template services.yaml arguments drift from the real constructors:\n" . implode( "\n", $violations )
    );
  }

  /**
   * Minimal parser for the template's own yaml shape: a service id, an
   * optional explicit `class:`, and a positional `arguments:` list of
   * '@Service' references. Factory blocks, named arguments, aliases and
   * resource globs are skipped — only hand-listed constructor args are
   * validated.
   *
   * @return array<int, array{class: string, arguments: string[]}>
   */
  private function parse_service_definitions( string $yaml ): array {
    $yaml = str_replace( '\\\\', '\\', $yaml );
    $services = [];
    $current_id = null;
    $current_class = null;
    $current_args = [];
    $skip = false;

    $flush = function () use ( &$services, &$current_id, &$current_class, &$current_args, &$skip ): void {
      if ( $current_id !== null && ! $skip && $current_args !== [] ) {
        $services[] = [
          'class' => $current_class ?? $current_id,
          'arguments' => $current_args,
        ];
      }
      $current_id = null;
      $current_class = null;
      $current_args = [];
      $skip = false;
    };

    foreach ( explode( "\n", $yaml ) as $line ) {
      if ( preg_match( '/^  ([A-Za-z][A-Za-z0-9_\\\\]*):\s*(~?)\s*$/', $line, $m ) ) {
        $flush();
        if ( $m[2] !== '~' && ! str_ends_with( $m[1], '\\' ) ) {
          $current_id = $m[1];
        }
        continue;
      }
      if ( $current_id === null ) {
        continue;
      }
      if ( preg_match( '/^    class:\s*(\S+)/', $line, $m ) ) {
        $current_class = $m[1];
      } elseif ( preg_match( '/^    (factory|alias|resource):/', $line ) ) {
        $skip = true;
      } elseif ( preg_match( "/^      - '@([^']+)'/", $line, $m ) ) {
        $current_args[] = $m[1];
      } elseif ( preg_match( '/^      \\\$/', $line ) ) {
        $skip = true; // named arguments — autowiring handles the rest
      }
    }
    $flush();

    return $services;
  }
}
