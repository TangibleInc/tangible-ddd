<?php

namespace TangibleDDD\Application\Logging;

use League\Tactician\Middleware;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Infra\IDDDConfig;
use Throwable;

use function TangibleDDD\WordPress\command_audit_enabled;
use function TangibleDDD\WordPress\command_audit_preflight;
use function TangibleDDD\WordPress\command_audit_finalise;

final class CommandAuditMiddleware implements Middleware {
  public function __construct(
    private readonly IDDDConfig $config,
    private readonly EventsUnitOfWork $events,
    private readonly Redactor $redactor
  ) {}

  public function execute($command, callable $next) {
    if (!command_audit_enabled($this->config)) {
      return $next($command);
    }

    $start_ts = microtime(true);
    $command_name = get_class($command);
    $command_id = $this->generate_id();

    // Share command_id with correlation context for outbox linking
    CorrelationContext::set_command_id($command_id);

    [$parameters, $redactions] = $this->redactor->redact(get_object_vars($command));
    $source = $this->resolve_source();
    $env = [
      'php' => PHP_VERSION,
      'wp' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : 'unknown',
      'plugin' => $this->config->version(),
    ];

    $blog_id = is_multisite() ? get_current_blog_id() : 1;
    command_audit_preflight($this->config, [
      'command_id' => $command_id,
      'correlation_id' => CorrelationContext::get(),
      'command_name' => $command_name,
      'source' => $source['type'],
      'source_id' => (string) ($source['id'] ?? ''),
      'blog_id' => $blog_id,
      'parameters' => $parameters,
      'environment' => $env,
    ]);

    $status = 'success';
    $error = null;

    try {
      return $next($command);

    } catch (Throwable $e) {
      $status = 'error';
      $error = [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'code' => (int) $e->getCode(),
      ];
      throw $e;

    } finally {
      $duration_ms = (int) round((microtime(true) - $start_ts) * 1000);
      $peak_memory_bytes = memory_get_peak_usage(true);

      $events = array_map(
        static fn($e) => ['name' => $e::name()],
        $this->events->published()
      );

      command_audit_finalise($this->config, [
        'command_id' => $command_id,
        'status' => $status,
        'duration_ms' => $duration_ms,
        'peak_memory_bytes' => $peak_memory_bytes,
        'events' => $events,
        'error' => $error,
      ]);
    }
  }

  private function generate_id(): string {
    try {
      return bin2hex(random_bytes(16));
    } catch (Throwable) {
      return dechex(time()) . '-' . (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid());
    }
  }

  private function resolve_source(): array {
    if (PHP_SAPI === 'cli') {
      return ['type' => 'cli'];
    }

    if (defined('DOING_CRON') && DOING_CRON) {
      return ['type' => 'system'];
    }

    $user_id = get_current_user_id();
    return $user_id ? ['type' => 'user', 'id' => $user_id] : ['type' => 'system'];
  }
}
