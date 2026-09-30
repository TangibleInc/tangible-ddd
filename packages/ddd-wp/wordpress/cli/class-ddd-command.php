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
   * [--version-const=<name>]
   * : Name of the PHP constant holding the plugin version. Used in the
   * post-scaffold "wiring snippet" that you paste into your main plugin file.
   * Defaults to '{PREFIX_UPPER}_VERSION' (e.g. 'TGBL_CRED_VERSION').
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
    $this->run_init( $args, $assoc_args );
  }

  /**
   * Announce an integration event — the human cold-start door.
   *
   * The ctor-as-schema codec makes every integration event JSON-constructible:
   * the payload keys are the constructor parameter names. The event is
   * published on its owning consumer's bus, so it rides the outbox exactly
   * like an organic fact — durable, journey-stamped, and indistinguishable
   * to whatever reacts (listeners, #[StartsOn] ignitions, #[Awaits] resumes).
   *
   * You never cold-start a process from here; you announce the fact that
   * ignites it.
   *
   * ## OPTIONS
   *
   * <event>
   * : Fully-qualified integration event class name.
   *
   * --payload=<json>
   * : JSON object whose keys are the event's constructor parameter names.
   *
   * ## EXAMPLES
   *
   *     wp ddd announce 'Tangible\Cred\Domain\Events\Completion\DeadlineCompletionStarted' \
   *       --payload='{"user_id":3,"course_ids":[22,23],"deadline_hours":72,"reason":"manual"}'
   */
  public function announce( $args, $assoc_args ) {
    [ $event_class ] = $args;
    $event_class = ltrim( (string) $event_class, '\\' );

    if ( ! class_exists( $event_class ) ) {
      \WP_CLI::error( "Class not found: $event_class" );
    }
    if ( ! is_a( $event_class, \TangibleDDD\Domain\Events\IIntegrationEvent::class, true ) ) {
      \WP_CLI::error( "$event_class is not an integration event." );
    }

    $payload = json_decode( (string) ( $assoc_args['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
      \WP_CLI::error( '--payload must be a JSON object (keys = constructor parameter names).' );
    }

    $event = $event_class::from_payload( $payload );

    // Find the owning consumer: its config prefixes the event's wire action.
    $action = $event_class::integration_action();
    $owner = null;
    foreach ( \TangibleDDD\WordPress\consumers() as $prefix => $handle ) {
      if ( str_starts_with( $action, $prefix . '_' ) ) {
        $owner = $handle;
        break;
      }
    }
    if ( $owner === null ) {
      \WP_CLI::error( "No registered consumer owns '$action' — is the plugin boot()ed?" );
    }

    $bus = $owner->container()->get( \TangibleDDD\Application\Events\IIntegrationEventBus::class );
    $bus->publish( $event );

    \WP_CLI::success( sprintf(
      "Announced %s on '%s' (consumer %s) — riding the outbox; reactions fire at drain.",
      $event_class,
      $action,
      $owner->prefix()
    ) );
  }

  private function run_init( $args, $assoc_args ) {
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

    // Default version constant from prefix (e.g. 'tgbl_cred' -> 'TGBL_CRED_VERSION')
    $version_const = $assoc_args['version-const'] ?? ( strtoupper( $prefix ) . '_VERSION' );

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
    $dirs = $this->get_directories();

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
    $templates = $this->get_templates( $prefix, $namespace, $version_const );

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
    WP_CLI::log( '' );
    WP_CLI::log( '  1. Ensure tangible-ddd is required via composer:' );
    WP_CLI::log( '       composer require tangible/ddd' );
    WP_CLI::log( '' );
    WP_CLI::log( '  2. Map the generated namespace in your composer.json and dump the autoloader:' );
    WP_CLI::log( "       \"autoload\": { \"psr-4\": { \"{$namespace}\\\\\\\\\": \"ddd-src/\" } }" );
    WP_CLI::log( '       composer dump-autoload' );
    WP_CLI::log( '' );
    WP_CLI::log( '  3. Schedule the generated DI index from your main plugin file' );
    WP_CLI::log( "     (after {$version_const} is defined and the autoloader is loaded)." );
    WP_CLI::log( '     The wrapper waits for the framework loader; the index carries' );
    WP_CLI::log( '     the whole handshake — container, boot(),' );
    WP_CLI::log( '     consumer registration, table migrations:' );
    WP_CLI::log( '' );
    WP_CLI::log( $this->template_main_plugin_snippet( $prefix, $namespace, $version_const ) );
    WP_CLI::log( '' );
    WP_CLI::log( '  4. Start creating domain events, commands, and queries!' );
  }

  /**
   * Convert prefix to PascalCase namespace.
   */
  private function prefix_to_namespace( string $prefix ): string {
    $parts = explode( '_', $prefix );
    return implode( '', array_map( 'ucfirst', $parts ) );
  }

  /** @return list<string> */
  private function get_directories(): array {
    return [
      'ddd-src/Domain/Events',
      'ddd-src/Domain/Shared',
      'ddd-src/Domain/Exceptions',
      'ddd-src/Domain/Repositories',
      'ddd-src/Application/Commands',
      'ddd-src/Application/CommandHandlers',
      'ddd-src/Application/Queries',
      'ddd-src/Application/QueryHandlers',
      'ddd-src/Application/EventHandlers',
      'ddd-src/Application/IntegrationListeners',
      'ddd-src/Application/Process',
      'ddd-src/Application/Services',
      'ddd-src/Infra/Persistence',
      'ddd-src/Infra/Services',
      'ddd-wordpress/di',
      'ddd-wordpress/tables',
      '.claude/skills/tangible-ddd',
    ];
  }

  /**
   * Render the post-scaffold wiring wrapper for the consumer's main plugin.
   * The winning framework copy defines boot() at plugins_loaded:1. The
   * generated wrapper follows the fleet convention of priority 10 before
   * carrying the whole handshake (container, boot(), registration, migrations).
   *
   * Intentionally emitted to stdout (not written to disk) so we never touch
   * the consumer's user-owned plugin entry file.
   */
  private function template_main_plugin_snippet( string $prefix, string $namespace, string $version_const ): string {
    return <<<PHP
// tangible-ddd: wait for the winning framework copy, then boot this consumer.
add_action( 'plugins_loaded', static function (): void {
  require_once __DIR__ . '/ddd-wordpress/di/index.php';
}, 10 );
PHP;
  }

  /**
   * Get all template files.
   */
  private function get_templates( string $prefix, string $namespace, string $version_const ): array {
    // 0.2.5c: the scaffold stamps NO classes. Identity is data (DDDConfig in
    // the boot declaration + services.yaml, resolved at runtime by
    // ConsumerRegistry::owner_of()) — events/commands/queries/VOs extend the
    // framework bases directly, and the bus convention ships once
    // (TangibleDDD\Application\CQRS\HandlerClassNameInflector). What remains
    // is the consumer's own wiring and the directory skeleton.
    return [
      'ddd-src/Domain/Events/.gitkeep' => '',
      'ddd-src/Domain/Services/.gitkeep' => '',
      'ddd-src/Domain/Exceptions/.gitkeep' => '',
      'ddd-src/Domain/Repositories/.gitkeep' => '',
      'ddd-src/Application/Commands/.gitkeep' => '',
      'ddd-src/Application/CommandHandlers/.gitkeep' => '',
      'ddd-src/Application/Queries/.gitkeep' => '',
      'ddd-src/Application/QueryHandlers/.gitkeep' => '',
      'ddd-src/Application/EventHandlers/.gitkeep' => '',
      'ddd-src/Application/IntegrationListeners/.gitkeep' => '',
      'ddd-src/Application/Process/.gitkeep' => '',
      'ddd-src/Application/Services/.gitkeep' => '',
      'ddd-src/Infra/Persistence/.gitkeep' => '',
      'ddd-src/Infra/Services/.gitkeep' => '',
      'ddd-wordpress/tables/.gitkeep' => '',
      'ddd-wordpress/di/index.php' => $this->template_di_index( $prefix, $namespace, $version_const ),
      'ddd-wordpress/di/services.yaml' => $this->template_services_yaml( $prefix, $namespace, $version_const ),
      'ddd-wordpress/di/tactician.yaml' => $this->template_tactician_yaml( $prefix, $namespace ),
      '.claude/skills/tangible-ddd/SKILL.md' => $this->template_consumer_skill(),
    ];
  }








  /** Keep consumer-local guidance tied to the installed framework copy. */
  private function template_consumer_skill(): string {
    return <<<'MARKDOWN'
---
name: tangible-ddd
description: Use when changing domain, application, event, process, workflow, or DDD container code in this WordPress plugin.
---

# Tangible DDD consumer handoff

Before changing this consumer:

1. Verify the installed `tangible/ddd` version with Composer. Do not assume the
   repository's newest docs match the consumer's lockfile or vendored copy.
2. Read the installed canonical guide at
   `vendor/tangible/ddd/.claude/skills/tangible-ddd/SKILL.md`. If Composer uses
   another install path, locate that package's copy instead.
3. When guidance conflicts, inspect that installed package's current source and
   tests. Runtime code and executable contracts win over remembered examples.
4. Then read this consumer's architecture docs, DI YAML, tests, and local
   conventions. Local guidance may specialize the framework contract; it must
   not silently replace it.

This file is only the handoff. Do not grow it into a copied framework manual.
MARKDOWN;
  }

  /**
   * Template: DI container setup.
   */
  private function template_di_index( string $prefix, string $namespace, string $version_const ): string {
    return <<<PHP
<?php

namespace {$namespace}\\WordPress\\DI;

use Symfony\\Component\\DependencyInjection\\ContainerBuilder;
use Symfony\\Component\\Config\\FileLocator;
use Symfony\\Component\\DependencyInjection\\Loader\\YamlFileLoader;
use TangibleDDD\\Infra\\DependencyInjection\\DDDCompilerPasses;

\$container_builder = new ContainerBuilder();

// Expose the plugin version to the container as a parameter so services.yaml
// can reference it via %{$prefix}.version% instead of hardcoding 'dev'.
\$container_builder->setParameter(
  '{$prefix}.version',
  defined( '{$version_const}' ) ? constant( '{$version_const}' ) : 'dev'
);

\$loader = new YamlFileLoader( \$container_builder, new FileLocator( __DIR__ ) );
\$loader->load( 'tactician.yaml' );
\$loader->load( 'services.yaml' );
DDDCompilerPasses::register( \$container_builder );

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

// The whole tangible-ddd handshake: announces this plugin to the consumer
// registry and defers register_hooks() to init:2 (after the compile above).
// Framework tables are created/healed by the migration lane on the first
// init tick — no activation hook needed. The generated main-plugin wrapper
// includes this file at plugins_loaded:10, after framework initialization.
\\TangibleDDD\\WordPress\\boot(
  new \\TangibleDDD\\Infra\\DDDConfig(
    prefix: '{$prefix}',
    namespace_root: '{$namespace}',
    version: defined( '{$version_const}' ) ? constant( '{$version_const}' ) : 'dev',
  ),
  static fn () => di()
);

PHP;
  }

  /**
   * Template: Services YAML.
   */
  private function template_services_yaml( string $prefix, string $namespace, string $version_const ): string {
    return <<<YAML
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  # Every registered LongProcess subclass is auto-tagged. The DDD compiler
  # pass materializes these process types into a runtime catalog before the
  # container is dumped; discovery definitions are never resolved as objects.
  _instanceof:
    TangibleDDD\\Application\\Process\\LongProcess:
      tags: ['ddd.long_process']

  # Config — the framework's concrete, parameterized with THIS consumer's
  # identity (0.2.5c: no stamped Infra\\Config class). Version is wired via
  # the {$prefix}.version container parameter set in di/index.php.
  TangibleDDD\\Infra\\DDDConfig:
    arguments:
      \$prefix: '{$prefix}'
      \$namespace_root: '{$namespace}'
      \$version: '%{$prefix}.version%'

  TangibleDDD\\Infra\\IDDDConfig:
    alias: TangibleDDD\\Infra\\DDDConfig

  # Correlation (the act bracket: guard + scope + audit record)
  TangibleDDD\\Application\\Correlation\\CorrelationMiddleware: ~

  # Transaction middleware
  TangibleDDD\\Application\\Persistence\\TransactionMiddleware: ~

  # Domain Event Dispatcher (no constructor — arguments here would be
  # silently discarded by PHP)
  TangibleDDD\\Application\\Events\\IDomainEventDispatcher:
    class: TangibleDDD\\Infra\\Services\\WordPressEventDispatcher

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
      - '@TangibleDDD\\Application\\Outbox\\OutboxConfig'

  # Resolved by the outbox processing cron (ddd hooks.php) every tick.
  # Autowired (~) by type so it can't drift from the constructor arg order.
  TangibleDDD\\Infra\\Services\\OutboxProcessor: ~

  # Integration Event Bus
  TangibleDDD\\Application\\Events\\IIntegrationEventBus:
    class: TangibleDDD\\Infra\\Services\\OutboxIntegrationEventBus

  # Long-running Processes
  TangibleDDD\\Infra\\IProcessRepository:
    class: TangibleDDD\\Infra\\Persistence\\ProcessRepository
    arguments:
      - '@TangibleDDD\\Infra\\IDDDConfig'

  # Autowired — hand-listed args drifted from the real constructor once
  # already and shipped to every consumer; don't reintroduce them.
  TangibleDDD\\Application\\Process\\ProcessRunner: ~

  {$namespace}\\Application\\Process\\:
    resource: '../../ddd-src/Application/Process'
    autowire: false
    shared: false
    public: false

  # Command Audit (optional - configure Redactor separately)
  TangibleDDD\\Application\\Logging\\Redactor: ~

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

  # Integration listeners: fact-in, command-out translators for the async
  # surface — eager-booted by register_hooks() alongside event handlers.
  {$namespace}\\Application\\IntegrationListeners\\:
    public: true
    resource: '../../ddd-src/Application/IntegrationListeners'

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
  # 1. CorrelationMiddleware - THE ACT BRACKET: guard + scope + audit record
  # 2. TransactionMiddleware - starts database transaction
  # 3. DomainEventsPublishMiddleware - publishes events (writes to outbox inside transaction)
  # 4. SelfExecutingCommandMiddleware - runs a SelfHandlingCommand's own handle()
  #    (terminal for those; short-circuits before the naming-convention resolver)
  # 5. CommandHandlerMiddleware - executes the handler (plain commands)
  League\\Tactician\\CommandBus:
    public: true
    arguments:
      - '@TangibleDDD\\Application\\Correlation\\CorrelationMiddleware'
      - '@TangibleDDD\\Application\\Persistence\\TransactionMiddleware'
      - '@TangibleDDD\\Application\\Events\\DomainEventsPublishMiddleware'
      - '@TangibleDDD\\Application\\CQRS\\SelfExecutingCommandMiddleware'
      - '@tactician.middleware.command_handler'

  # Runs a SelfHandlingCommand's or SelfHandlingQuery's own handle() by
  # reflection, method-injecting its dependencies (one middleware serves both
  # buses). Explicit @service_container (not autowired by type).
  TangibleDDD\\Application\\CQRS\\SelfExecutingCommandMiddleware:
    arguments: ['@service_container']

  # Dedicated Query Bus (read-only pipeline — no act bracket: queries are
  # reads, not moments). The self-executing middleware is terminal for a
  # SelfHandlingQuery and returns the read result; nothing else belongs here.
  tactician.query_bus:
    class: League\\Tactician\\CommandBus
    public: true
    arguments:
      - '@TangibleDDD\\Application\\CQRS\\SelfExecutingCommandMiddleware'
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
    class: TangibleDDD\\Application\\CQRS\\HandlerClassNameInflector

  tactician.method_name_inflector:
    class: League\\Tactician\\Handler\\Mapping\\MethodName\\Handle

YAML;
  }

}
