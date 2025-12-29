<?php

namespace TangibleDDD\WordPress\CLI;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Scaffolds DDD framework files for a consumer plugin.
 *
 * ## EXAMPLES
 *
 *     # Initialize DDD in current directory
 *     wp ddd init --prefix=my_plugin --namespace=MyPlugin
 *
 *     # Initialize in a specific directory
 *     wp ddd init --prefix=acme_orders --namespace=Acme\\Orders --plugin-path=/path/to/plugin
 */
class DDD_Command {

  /**
   * Initialize DDD framework scaffolding for a plugin.
   *
   * ## OPTIONS
   *
   * --prefix=<prefix>
   * : Plugin prefix for database tables, hooks, and options (e.g., 'tgbl_cred').
   *
   * [--namespace=<namespace>]
   * : PHP namespace for generated classes (e.g., 'MyPlugin' or 'Acme\\Orders').
   * Defaults to PascalCase of prefix.
   *
   * [--plugin-path=<path>]
   * : Path to the plugin directory. Defaults to current directory.
   *
   * [--force]
   * : Overwrite existing files without prompting.
   *
   * ## EXAMPLES
   *
   *     wp ddd init --prefix=my_plugin --namespace=MyPlugin
   *     wp ddd init --prefix=acme_orders --namespace=Acme\\Orders --plugin-path=./plugins/acme-orders
   *
   * @when before_wp_load
   */
  public function init( $args, $assoc_args ) {
    $prefix = $assoc_args['prefix'] ?? null;
    $path = $assoc_args['plugin-path'] ?? getcwd();
    $force = Utils\get_flag_value( $assoc_args, 'force', false );

    if ( ! $prefix ) {
      WP_CLI::error( 'The --prefix argument is required.' );
    }

    // Validate prefix format
    if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $prefix ) ) {
      WP_CLI::error( 'Prefix must start with a letter and contain only lowercase letters, numbers, and underscores.' );
    }

    // Default namespace from prefix
    $namespace = $assoc_args['namespace'] ?? $this->prefix_to_namespace( $prefix );

    // Normalize path
    $path = rtrim( realpath( $path ) ?: $path, '/' );

    if ( ! is_dir( $path ) ) {
      WP_CLI::error( "Directory does not exist: {$path}" );
    }

    WP_CLI::log( "Initializing DDD framework in: {$path}" );
    WP_CLI::log( "Prefix: {$prefix}" );
    WP_CLI::log( "Namespace: {$namespace}" );
    WP_CLI::log( '' );

    $files_created = 0;

    // Create directory structure
    $dirs = [
      'ddd-src/Domain/Events',
      'ddd-src/Domain/Shared',
      'ddd-src/Domain/Exceptions',
      'ddd-src/Domain/Repositories',
      'ddd-src/Application/Commands',
      'ddd-src/Application/CommandHandlers',
      'ddd-src/Application/Queries',
      'ddd-src/Application/QueryHandlers',
      'ddd-src/Application/EventHandlers',
      'ddd-src/Application/Services',
      'ddd-src/Infra/Persistence',
      'ddd-src/Infra/Services',
      'ddd-wordpress/di',
      'ddd-wordpress/tables',
    ];

    foreach ( $dirs as $dir ) {
      $full_path = "{$path}/{$dir}";
      if ( ! is_dir( $full_path ) ) {
        if ( ! mkdir( $full_path, 0755, true ) ) {
          WP_CLI::warning( "Could not create directory: {$dir}" );
        } else {
          WP_CLI::log( "Created directory: {$dir}" );
        }
      }
    }

    // Generate files
    $templates = $this->get_templates( $prefix, $namespace );

    foreach ( $templates as $file => $content ) {
      $full_path = "{$path}/{$file}";

      if ( file_exists( $full_path ) && ! $force ) {
        WP_CLI::warning( "File exists, skipping: {$file} (use --force to overwrite)" );
        continue;
      }

      if ( file_put_contents( $full_path, $content ) !== false ) {
        WP_CLI::log( "Created: {$file}" );
        $files_created++;
      } else {
        WP_CLI::warning( "Could not create: {$file}" );
      }
    }

    WP_CLI::log( '' );
    WP_CLI::success( "DDD scaffolding complete! {$files_created} files created." );
    WP_CLI::log( '' );
    WP_CLI::log( 'Next steps:' );
    WP_CLI::log( '  1. Ensure tangible-ddd plugin is active' );
    WP_CLI::log( '  2. Include ddd-wordpress/di/index.php in your plugin bootstrap' );
    WP_CLI::log( '  3. Start creating domain events, commands, and queries!' );
  }

  /**
   * Convert prefix to PascalCase namespace.
   */
  private function prefix_to_namespace( string $prefix ): string {
    $parts = explode( '_', $prefix );
    return implode( '', array_map( 'ucfirst', $parts ) );
  }

  /**
   * Get all template files.
   */
  private function get_templates( string $prefix, string $namespace ): array {
    return [
      'ddd-src/Domain/Events/DomainEvent.php' => $this->template_domain_event( $prefix, $namespace ),
      'ddd-src/Domain/Events/IntegrationEvent.php' => $this->template_integration_event( $prefix, $namespace ),
      'ddd-src/Domain/Services/.gitkeep' => '',
      'ddd-src/Domain/Shared/.gitkeep' => '',
      'ddd-src/Domain/Exceptions/.gitkeep' => '',
      'ddd-src/Domain/Repositories/.gitkeep' => '',
      'ddd-src/Application/Commands/.gitkeep' => '',
      'ddd-src/Application/CommandHandlers/.gitkeep' => '',
      'ddd-src/Application/Queries/.gitkeep' => '',
      'ddd-src/Application/QueryHandlers/.gitkeep' => '',
      'ddd-src/Application/EventHandlers/.gitkeep' => '',
      'ddd-src/Application/Services/.gitkeep' => '',
      'ddd-src/Infra/Persistence/.gitkeep' => '',
      'ddd-src/Infra/Services/.gitkeep' => '',
      'ddd-wordpress/tables/.gitkeep' => '',
      'ddd-src/Infra/Config.php' => $this->template_config( $prefix, $namespace ),
      'ddd-wordpress/di/index.php' => $this->template_di_index( $prefix, $namespace ),
      'ddd-wordpress/di/services.yaml' => $this->template_services_yaml( $prefix, $namespace ),
      'ddd-wordpress/di/tactician.yaml' => $this->template_tactician_yaml( $prefix, $namespace ),
      'ddd-wordpress/di/HandlerClassNameInflector.php' => $this->template_handler_inflector( $prefix, $namespace ),
    ];
  }

  /**
   * Template: Domain Event base class.
   */
  private function template_domain_event( string $prefix, string $namespace ): string {
    return <<<PHP
<?php

namespace {$namespace}\\Domain\\Events;

use TangibleDDD\\Domain\\Events\\DomainEvent as BaseDomainEvent;

/**
 * Base class for {$namespace} domain events.
 *
 * All domain events in this plugin should extend this class.
 */
abstract class DomainEvent extends BaseDomainEvent {
  protected static function prefix(): string {
    return '{$prefix}';
  }
}

PHP;
  }

  /**
   * Template: Integration Event base class.
   */
  private function template_integration_event( string $prefix, string $namespace ): string {
    return <<<PHP
<?php

namespace {$namespace}\\Domain\\Events;

use TangibleDDD\\Domain\\Events\\IntegrationEvent as BaseIntegrationEvent;

/**
 * Base class for {$namespace} integration events.
 *
 * Integration events are published through the outbox to ActionScheduler
 * for reliable async processing.
 */
abstract class IntegrationEvent extends BaseIntegrationEvent {
  protected static function prefix(): string {
    return '{$prefix}';
  }
}

PHP;
  }

  /**
   * Template: Config class implementing IDDDConfig.
   */
  private function template_config( string $prefix, string $namespace ): string {
    $prefix_dashed = str_replace( '_', '-', $prefix );

    return <<<PHP
<?php

namespace {$namespace}\\Infra;

use TangibleDDD\\Infra\\IDDDConfig;

/**
 * DDD Framework configuration for {$namespace}.
 */
class Config implements IDDDConfig {

  public function __construct(
    private readonly string \$version = 'dev',
  ) {}

  public function prefix(): string {
    return '{$prefix}';
  }

  public function table( string \$name ): string {
    global \$wpdb;
    return \$wpdb->prefix . '{$prefix}_' . \$name;
  }

  public function hook( string \$name ): string {
    return '{$prefix}_' . \$name;
  }

  public function as_group( string \$name ): string {
    return '{$prefix_dashed}-' . \$name;
  }

  public function option( string \$name ): string {
    return '{$prefix}_' . \$name;
  }

  public function domain_action( string \$event_name ): string {
    return '{$prefix}_domain_' . \$event_name;
  }

  public function integration_action( string \$event_name ): string {
    return '{$prefix}_integration_' . \$event_name;
  }

  public function version(): string {
    return \$this->version;
  }
}

PHP;
  }

  /**
   * Template: DI container setup.
   */
  private function template_di_index( string $prefix, string $namespace ): string {
    return <<<PHP
<?php

namespace {$namespace}\\WordPress\\DI;

require_once __DIR__ . '/HandlerClassNameInflector.php';

use Symfony\\Component\\DependencyInjection\\ContainerBuilder;
use Symfony\\Component\\Config\\FileLocator;
use Symfony\\Component\\DependencyInjection\\Loader\\YamlFileLoader;

\$container_builder = new ContainerBuilder();

\$loader = new YamlFileLoader( \$container_builder, new FileLocator( __DIR__ ) );
\$loader->load( 'tactician.yaml' );
\$loader->load( 'services.yaml' );

/**
 * Get the DI container.
 *
 * @param ContainerBuilder|null \$container_instance Override container (for testing)
 * @return ContainerBuilder
 */
function di( ?ContainerBuilder \$container_instance = null ): ContainerBuilder {
  static \$container;

  // Allow override in tests
  if ( defined( 'DOING_TANGIBLE_TESTS' ) && \$container_instance ) {
    \$container = \$container_instance;
  }

  return \$container ?: ( \$container = \$container_instance );
}
di( \$container_builder );

/**
 * Compile the container on WordPress init.
 */
function compile_container() {
  \$container = di();

  do_action( '{$prefix}_pre_compile_di', \$container );
  \$container->compile();
  do_action( '{$prefix}_post_compile_di', \$container );
}

add_action( 'init', __NAMESPACE__ . '\\compile_container', 1 );

PHP;
  }

  /**
   * Template: Services YAML.
   */
  private function template_services_yaml( string $prefix, string $namespace ): string {
    return <<<YAML
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  # Config
  {$namespace}\\Infra\\Config:
    arguments:
      \$version: 'dev'

  TangibleDDD\\Infra\\IDDDConfig:
    alias: {$namespace}\\Infra\\Config

  # Correlation
  TangibleDDD\\Application\\Correlation\\CorrelationMiddleware: ~
  TangibleDDD\\Application\\Correlation\\CorrelationContext: ~

  # Transaction middleware
  TangibleDDD\\Application\\Persistence\\TransactionMiddleware: ~

  # Domain Event Dispatcher
  TangibleDDD\\Application\\Events\\IDomainEventDispatcher:
    class: TangibleDDD\\Infra\\Services\\WordPressEventDispatcher
    arguments:
      - '@TangibleDDD\\Infra\\IDDDConfig'

  # Events Unit of Work and Router
  TangibleDDD\\Application\\Events\\EventsUnitOfWork: ~
  TangibleDDD\\Application\\Events\\EventRouter: ~
  TangibleDDD\\Application\\Events\\DomainEventsPublishMiddleware:
    arguments:
      - '@TangibleDDD\\Application\\Events\\EventsUnitOfWork'
      - '@TangibleDDD\\Application\\Events\\EventRouter'

  # Outbox
  TangibleDDD\\Application\\Outbox\\OutboxConfig:
    factory: ['TangibleDDD\\Application\\Outbox\\OutboxConfig', 'from_options']
    arguments:
      - '@TangibleDDD\\Infra\\IDDDConfig'

  TangibleDDD\\Infra\\IOutboxRepository:
    class: TangibleDDD\\Infra\\Persistence\\OutboxRepository
    arguments:
      - '@TangibleDDD\\Infra\\IDDDConfig'
      - '@TangibleDDD\\Application\\Outbox\\OutboxConfig'

  TangibleDDD\\Application\\Outbox\\IOutboxPublisher:
    class: TangibleDDD\\Infra\\Services\\ActionSchedulerOutboxPublisher
    arguments:
      - '@TangibleDDD\\Infra\\IDDDConfig'

  TangibleDDD\\Application\\Outbox\\OutboxProcessor:
    arguments:
      - '@TangibleDDD\\Infra\\IOutboxRepository'
      - '@TangibleDDD\\Application\\Outbox\\IOutboxPublisher'
      - '@TangibleDDD\\Application\\Outbox\\OutboxConfig'

  # Integration Event Bus
  TangibleDDD\\Application\\Events\\IIntegrationEventBus:
    class: TangibleDDD\\Infra\\Services\\OutboxIntegrationEventBus
    arguments:
      - '@TangibleDDD\\Infra\\IOutboxRepository'

  # Long-running Processes
  TangibleDDD\\Infra\\IProcessRepository:
    class: TangibleDDD\\Infra\\Persistence\\ProcessRepository
    arguments:
      - '@TangibleDDD\\Infra\\IDDDConfig'

  TangibleDDD\\Application\\Process\\ProcessRunner:
    arguments:
      - '@TangibleDDD\\Infra\\IProcessRepository'
      - '@TangibleDDD\\Application\\Correlation\\CorrelationContext'

  # Command Audit (optional - configure Redactor separately)
  TangibleDDD\\Application\\Logging\\Redactor: ~
  TangibleDDD\\Application\\Logging\\CommandAuditMiddleware:
    arguments:
      - '@TangibleDDD\\Application\\Events\\EventsUnitOfWork'
      - '@TangibleDDD\\Application\\Logging\\Redactor'

  # Plugin-specific service autoloading
  {$namespace}\\Application\\CommandHandlers\\:
    public: true
    resource: '../../ddd-src/Application/CommandHandlers'
    shared: false

  {$namespace}\\Application\\QueryHandlers\\:
    public: true
    resource: '../../ddd-src/Application/QueryHandlers'
    shared: false

  {$namespace}\\Application\\EventHandlers\\:
    public: true
    resource: '../../ddd-src/Application/EventHandlers'

  {$namespace}\\Application\\Services\\:
    public: true
    resource: '../../ddd-src/Application/Services'

  {$namespace}\\Domain\\Services\\:
    public: true
    resource: '../../ddd-src/Domain/Services'

  {$namespace}\\Infra\\Services\\:
    public: true
    resource: '../../ddd-src/Infra/Services'

  {$namespace}\\Infra\\Persistence\\:
    public: true
    resource: '../../ddd-src/Infra/Persistence'

YAML;
  }

  /**
   * Template: Tactician bus YAML.
   */
  private function template_tactician_yaml( string $prefix, string $namespace ): string {
    return <<<YAML
services:
  # Tactician Command Bus
  # Middleware order:
  # 1. CommandAuditMiddleware - logs command, generates command_id
  # 2. CorrelationMiddleware - initializes correlation context
  # 3. TransactionMiddleware - starts database transaction
  # 4. DomainEventsPublishMiddleware - publishes events (writes to outbox inside transaction)
  # 5. CommandHandlerMiddleware - executes the handler
  League\\Tactician\\CommandBus:
    public: true
    arguments:
      - '@TangibleDDD\\Application\\Logging\\CommandAuditMiddleware'
      - '@TangibleDDD\\Application\\Correlation\\CorrelationMiddleware'
      - '@TangibleDDD\\Application\\Persistence\\TransactionMiddleware'
      - '@TangibleDDD\\Application\\Events\\DomainEventsPublishMiddleware'
      - '@tactician.middleware.command_handler'

  # Dedicated Query Bus (read-only pipeline, no middleware)
  tactician.query_bus:
    class: League\\Tactician\\CommandBus
    public: true
    arguments:
      - '@tactician.middleware.query_handler'

  tactician.middleware.command_handler:
    class: League\\Tactician\\Handler\\CommandHandlerMiddleware
    arguments:
      - '@service_container'
      - '@tactician.command_to_handler_mapping'

  tactician.middleware.query_handler:
    class: League\\Tactician\\Handler\\CommandHandlerMiddleware
    arguments:
      - '@service_container'
      - '@tactician.query_to_handler_mapping'

  tactician.command_to_handler_mapping:
    class: League\\Tactician\\Handler\\Mapping\\MapByNamingConvention
    arguments:
      - '@tactician.class_name_inflector'
      - '@tactician.method_name_inflector'

  tactician.query_to_handler_mapping:
    class: League\\Tactician\\Handler\\Mapping\\MapByNamingConvention
    arguments:
      - '@tactician.class_name_inflector'
      - '@tactician.method_name_inflector'

  tactician.class_name_inflector:
    class: {$namespace}\\WordPress\\DI\\HandlerClassNameInflector

  tactician.method_name_inflector:
    class: League\\Tactician\\Handler\\Mapping\\MethodName\\Handle

YAML;
  }

  /**
   * Template: Handler class name inflector.
   */
  private function template_handler_inflector( string $prefix, string $namespace ): string {
    return <<<PHP
<?php

namespace {$namespace}\\WordPress\\DI;

use League\\Tactician\\Handler\\Mapping\\ClassName\\ClassNameInflector;

/**
 * Maps Command/Query classes to their Handler classes.
 *
 * Convention:
 *   {$namespace}\\Application\\Commands\\CreateOrder -> {$namespace}\\Application\\CommandHandlers\\CreateOrderHandler
 *   {$namespace}\\Application\\Queries\\GetOrder -> {$namespace}\\Application\\QueryHandlers\\GetOrderHandler
 */
class HandlerClassNameInflector implements ClassNameInflector {

  private array \$command_values = [ 'singular' => 'Command', 'plural' => 'Commands' ];
  private array \$query_values   = [ 'singular' => 'Query', 'plural' => 'Queries' ];

  private function isCommand( string \$commandClassName ): bool {
    return str_contains( \$commandClassName, \$this->command_values['plural'] );
  }

  private function isQuery( string \$commandClassName ): bool {
    return str_contains( \$commandClassName, \$this->query_values['plural'] );
  }

  public function getClassName( string \$commandClassName ): string {
    if ( \$this->isCommand( \$commandClassName ) ) {
      \$values = \$this->command_values;
    } elseif ( \$this->isQuery( \$commandClassName ) ) {
      \$values = \$this->query_values;
    } else {
      throw new \\LogicException( 'Command/Query bus expects either a Command or a Query' );
    }

    \$handler_name = str_replace( \$values['plural'], "{\$values['singular']}Handlers", \$commandClassName );
    \$handler_name = substr_replace( \$handler_name, 'Handler', strrpos( \$handler_name, \$values['singular'] ) );

    return \$handler_name;
  }
}

PHP;
  }
}
