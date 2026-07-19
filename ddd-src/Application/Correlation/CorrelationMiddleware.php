<?php

namespace TangibleDDD\Application\Correlation;

use League\Tactician\Middleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\IDDDConfig;
use Throwable;

use function TangibleDDD\WordPress\command_audit_enabled;
use function TangibleDDD\WordPress\command_audit_preflight;
use function TangibleDDD\WordPress\command_audit_finalise;
use function TangibleDDD\WordPress\touches_record;

/**
 * THE ACT BRACKET (0.3, spec §6.2): guard + scope + the audit record, one
 * owner. The record is written at bracket-open, where the ENCLOSING cause
 * is still visible — two separate middlewares can't both see the parent and
 * own the scope (build ruling #1; OTel's answer: the span record is written
 * by whatever opens the scope). The audit toggle skips only the write; the
 * guard holds on every install (the 0.2.4 lesson).
 *
 * A command's audit row is the projection of the context it was born into:
 * correlation = the enclosing story, causation = the enclosing cause in the
 * at-rest dialect. Inside the scope, Correlation::current()->cause is this
 * act — facts published take their raiser edge from it.
 */
final class CorrelationMiddleware implements Middleware {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly EventsUnitOfWork $events,
    private readonly Redactor $redactor,
  ) {}

  public function execute($command, callable $next) {
    $enclosing = $this->enclosing_context();

    // No command inside a command — acts are the atomic moments and never nest.
    if ($enclosing->cause?->kind === Kind::Act) {
      throw new CommandDispatchedInsideCommand(
        get_class($command),
        $enclosing->cause->label ?? $enclosing->cause->id
      );
    }
    $command_id = bin2hex(random_bytes(16));
    $command_name = get_class($command);
    $audit = command_audit_enabled($this->config);
    $start_ts = microtime(true);

    if ($audit) {
      [$parameters] = $this->redactor->redact(get_object_vars($command));
      $source = $this->resolve_source();

      command_audit_preflight($this->config, [
        'command_id' => $command_id,
        'correlation_id' => $enclosing->correlation_id,
        'command_name' => $command_name,
        'source' => $source['type'],
        'source_id' => (string) ($source['id'] ?? ''),
        'causation_id' => $enclosing->cause?->id,
        'causation_type' => $enclosing->cause?->causation_type(),
        'blog_id' => is_multisite() ? get_current_blog_id() : 1,
        'parameters' => $parameters,
        'environment' => [
          'php' => PHP_VERSION,
          'wp' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : 'unknown',
          'plugin' => $this->config->version(),
        ],
      ]);
    }

    $status = 'success';
    $error = null;

    try {
      // No dual-writes, no re-seeds — the scope IS the mechanism.
      return Correlation::within(
        $enclosing->for_act($command_id, $command_name),
        static fn () => $next($command)
      );

    } catch (Throwable $e) {
      $status = 'error';
      $error = ['type' => get_class($e), 'message' => $e->getMessage(), 'code' => (int) $e->getCode()];
      throw $e;

    } finally {
      if ($audit) {
        // The act's footprint (spec appendix 9): each published fact's entry
        // carries its declared touches — provenance stays attached to the
        // fact that made it — and the flat rows feed the touches index.
        // Footprint::of_event never throws (post-commit decoration).
        $event_entries = [];
        $touch_rows = [];
        foreach ($this->events->published() as $e) {
          $entry = ['name' => $e::name()];

          // The stamps live on the ANNOUNCED RECORD (the twin, in two-class
          // style; the event itself for self-publishers) — record-first,
          // source-fallback. Plain domain events have no record: their
          // touches harvest with no outbox identity to link.
          $record = \TangibleDDD\Application\Events\PublishedFacts::fact_of($e);
          $touches = \TangibleDDD\Application\Events\Footprint::of_event($record ?? $e);
          if ($touches === [] && $record !== null && $record !== $e) {
            $touches = \TangibleDDD\Application\Events\Footprint::of_event($e);
          }

          if ($touches !== []) {
            $entry['touches'] = $touches;
            foreach ($touches as $touch) {
              $touch_rows[] = [
                'aggregate' => $touch['aggregate'],
                'aggregate_id' => $touch['id'],
                'op' => $touch['op'],
                'event_name' => $e::name(),
                'event_id' => $record !== null
                  ? \TangibleDDD\Application\Events\PublishedFacts::id_of($record)
                  : null,
                'command_id' => $command_id,
                'correlation_id' => $enclosing->correlation_id,
              ];
            }
          }
          $event_entries[] = $entry;
        }

        command_audit_finalise($this->config, [
          'command_id' => $command_id,
          'status' => $status,
          'duration_ms' => (int) round((microtime(true) - $start_ts) * 1000),
          'peak_memory_bytes' => memory_get_peak_usage(true),
          'events' => $event_entries,
          'error' => $error,
        ]);

        if ($touch_rows !== []) {
          touches_record($this->config, $touch_rows);
        }
      }
    }
  }

  /**
   * The ambient scope wins; a genuinely flat dispatch (REST, CLI, WP hook)
   * is the root of a fresh story. root() mints WITHOUT touching the ambient
   * — deriving the enclosing context must never persist a mint into the
   * worker.
   */
  private function enclosing_context(): TraceContext {
    return Correlation::peek() ?? TraceContext::root();
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
