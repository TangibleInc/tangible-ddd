<?php

namespace TangibleDDD\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
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

  private static string $main_plugin_snippet;

  public static function setUpBeforeClass(): void {
    require_once dirname( __DIR__, 3 ) . '/ddd-wordpress/cli/class-ddd-command.php';

    $command = ( new \ReflectionClass( DDD_Command::class ) )->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod( DDD_Command::class, 'get_templates' );
    $method->setAccessible( true );

    self::$templates = $method->invoke( $command, 'acme_orders', 'AcmeOrders', 'ACME_ORDERS_VERSION' );

    $snippet = new \ReflectionMethod( DDD_Command::class, 'template_main_plugin_snippet' );
    $snippet->setAccessible( true );
    self::$main_plugin_snippet = $snippet->invoke(
      $command,
      'acme_orders',
      'AcmeOrders',
      'ACME_ORDERS_VERSION',
    );
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

  // ── 0.2.5c: the scaffold stamps no classes ──────────────────────────

  public function test_the_scaffold_stamps_no_classes(): void {
    $php_files = array_filter(
      array_keys( self::$templates ),
      static fn ( string $file ) => str_ends_with( $file, '.php' ),
    );

    $this->assertSame(
      [ 'ddd-wordpress/di/index.php' ],
      array_values( $php_files ),
      'every stamped class was consumer-repeated framework knowledge; ' .
      'identity is data now (DDDConfig + owner_of)'
    );
  }

  public function test_boot_declares_a_dddconfig_with_explicit_namespace_root(): void {
    $index = self::$templates['ddd-wordpress/di/index.php'];

    $this->assertStringContainsString( 'TangibleDDD\\Infra\\DDDConfig', $index );
    $this->assertStringContainsString( "prefix: 'acme_orders'", $index );
    $this->assertStringContainsString( "namespace_root: 'AcmeOrders'", $index );
  }

  public function test_tactician_binds_the_framework_inflector(): void {
    $yaml = self::$templates['ddd-wordpress/di/tactician.yaml'];

    $this->assertStringContainsString( 'TangibleDDD\Application\CQRS\HandlerClassNameInflector', $yaml );
    $this->assertStringNotContainsString( 'AcmeOrders\WordPress\DI\HandlerClassNameInflector', $yaml );
  }

  public function test_long_processes_are_compiled_into_the_generated_consumer_catalog(): void {
    $this->assertArrayHasKey( 'ddd-src/Application/Process/.gitkeep', self::$templates );

    $services = Yaml::parse( self::$templates['ddd-wordpress/di/services.yaml'] )['services'];
    $process_resource_key = 'AcmeOrders\Application\Process' . '\\';
    $this->assertArrayHasKey( $process_resource_key, $services );
    $this->assertSame( '../../ddd-src/Application/Process', $services[ $process_resource_key ]['resource'] );
    $this->assertFalse( $services[ $process_resource_key ]['autowire'] );
    $this->assertFalse( $services[ $process_resource_key ]['shared'] );
    $this->assertFalse( $services[ $process_resource_key ]['public'] );
    $this->assertSame(
      [ 'ddd.long_process' ],
      $services['_instanceof']['TangibleDDD\Application\Process\LongProcess']['tags'],
    );

    $index = self::$templates['ddd-wordpress/di/index.php'];
    $this->assertStringContainsString(
      'use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;',
      $index,
    );
    $this->assertStringContainsString(
      'DDDCompilerPasses::register( $container_builder );',
      $index,
    );
    $this->assertLessThan(
      strpos( $index, '$container->compile();' ),
      strpos( $index, 'DDDCompilerPasses::register( $container_builder );' ),
      'the compiler pass must be registered before the generated container compiles',
    );
  }

  public function test_main_plugin_snippet_waits_for_the_framework_loader(): void {
    $framework = dirname( __DIR__, 3 );
    $temp = sys_get_temp_dir() . '/ddd-scaffold-load-order-' . bin2hex( random_bytes( 8 ) );
    $di_dir = $temp . '/ddd-wordpress/di';
    $stubs_file = $temp . '/stubs.php';
    $plugin_file = $temp . '/plugin.php';
    $index_file = $di_dir . '/index.php';

    $this->assertTrue( mkdir( $di_dir, 0777, true ) );

    $stubs = <<<'PHP'
<?php

namespace Symfony\Component\DependencyInjection\Compiler {
  interface CompilerPassInterface {
    public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void;
  }

  final class PassConfig {
    public const TYPE_BEFORE_REMOVING = 'beforeRemoving';
  }
}

namespace Symfony\Component\DependencyInjection {
  final class ContainerBuilder {
    public function setParameter(string $name, mixed $value): void {}
    public function addCompilerPass(Compiler\CompilerPassInterface $pass): void {}
    public function compile(): void {}
  }
}

namespace Symfony\Component\Config {
  final class FileLocator {
    public function __construct(string $path) {}
  }
}

namespace Symfony\Component\DependencyInjection\Loader {
  final class YamlFileLoader {
    public function __construct(mixed $container, mixed $locator) {}
    public function load(string $file): void {}
  }
}

namespace {
  class wpdb {
    public string $prefix = 'wp_';
  }

  $GLOBALS['wpdb'] = new wpdb();
  $GLOBALS['_load_order_actions'] = [];
  $GLOBALS['_load_order_did'] = [];
  $GLOBALS['_load_order_current'] = null;

  function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['_load_order_actions'][$hook][$priority][] = $callback;
  }

  function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    add_action($hook, $callback, $priority, $accepted_args);
  }

  function do_action(string $hook, mixed ...$args): void {
    $GLOBALS['_load_order_current'] = $hook;
    $GLOBALS['_load_order_did'][$hook] = ($GLOBALS['_load_order_did'][$hook] ?? 0) + 1;
    $priorities = $GLOBALS['_load_order_actions'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
      foreach ($callbacks as $callback) {
        $callback(...$args);
      }
    }
    $GLOBALS['_load_order_current'] = null;
  }

  function did_action(string $hook): int {
    return $GLOBALS['_load_order_did'][$hook] ?? 0;
  }

  function doing_action(?string $hook = null): bool {
    return $hook === null
      ? $GLOBALS['_load_order_current'] !== null
      : $GLOBALS['_load_order_current'] === $hook;
  }
}
PHP;

    $plugin = "<?php\n\n"
      . 'require_once ' . var_export( $stubs_file, true ) . ";\n"
      . 'require_once ' . var_export( $framework . '/tangible-ddd.php', true ) . ";\n\n"
      . self::$main_plugin_snippet . "\n\n"
      . "do_action('plugins_loaded');\n"
      . "echo function_exists('TangibleDDD\\\\WordPress\\\\boot') ? 'BOOT_READY' : 'BOOT_MISSING';\n";

    try {
      $this->assertNotFalse( file_put_contents( $stubs_file, $stubs ) );
      $this->assertNotFalse( file_put_contents( $index_file, self::$templates['ddd-wordpress/di/index.php'] ) );
      $this->assertNotFalse( file_put_contents( $plugin_file, $plugin ) );

      $output = [];
      $exit_code = 0;
      exec(
        escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $plugin_file ) . ' 2>&1',
        $output,
        $exit_code,
      );

      $this->assertSame( 0, $exit_code, implode( "\n", $output ) );
      $this->assertContains( 'BOOT_READY', $output );
    } finally {
      foreach ( [ $plugin_file, $stubs_file, $index_file ] as $file ) {
        if ( file_exists( $file ) ) {
          unlink( $file );
        }
      }
      foreach ( [ $di_dir, dirname( $di_dir ), $temp ] as $directory ) {
        if ( is_dir( $directory ) ) {
          rmdir( $directory );
        }
      }
    }
  }
}
