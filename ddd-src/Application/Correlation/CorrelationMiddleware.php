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
 *
 * TRANSITIONAL (until the drain and wake lanes migrate): the enclosing
 * context is facade-first, legacy-fallback — a 0.2.x drain that armed only
 * CorrelationContext must still parent this command and share its story.
 * Inside the scope the legacy statics are dual-written for un-migrated
 * readers (outbox linking, the runner's guards). The dual-writes die with
 * the dissolution commit.
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
    if (null !== $inside = CorrelationContext::command_frame()) {
      // transitional belt-and-braces: a legacy-only marker (should be
      // unreachable once every dispatch runs through this bracket)
      throw new CommandDispatchedInsideCommand(get_class($command), $inside);
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
      $ambient_was_set = CorrelationContext::peek() !== null;

      return Correlation::within(
        $enclosing->for_act($command_id, $command_name),
        function () use ($command, $next, $command_id, $command_name, $enclosing, $ambient_was_set) {
          // dual-write for un-migrated readers (dies with the dissolution)
          CorrelationContext::enter($enclosing->correlation_id);
          CorrelationContext::mark_command_frame($command_name);
          CorrelationContext::set_command_id($command_id);
          try {
            return $next($command);
          } finally {
            CorrelationContext::clear_command_frame();
            CorrelationContext::leave();

            // leave() on the outermost scope exit reset()s the ambient —
            // including a DRAIN's correlation and armed causation, which the
            // drain owns until ITS teardown. This silently broke sibling
            // commands in real 0.2.5 chains (second command of one listener:
            // fresh story, null parent). Re-seed ONLY what the bracket found
            // already set — a top-level command's fresh mint still clears
            // (worker hygiene). Dies when the drain lane opens real scopes.
            if ($ambient_was_set && CorrelationContext::peek() === null) {
              CorrelationContext::init($enclosing->correlation_id);
              if ($enclosing->cause !== null) {
                CorrelationContext::set_causation($enclosing->cause->id, $enclosing->cause->causation_type());
              }
            }
          }
        }
      );

    } catch (Throwable $e) {
      $status = 'error';
      $error = ['type' => get_class($e), 'message' => $e->getMessage(), 'code' => (int) $e->getCode()];
      throw $e;

    } finally {
      if ($audit) {
        command_audit_finalise($this->config, [
          'command_id' => $command_id,
          'status' => $status,
          'duration_ms' => (int) round((microtime(true) - $start_ts) * 1000),
          'peak_memory_bytes' => memory_get_peak_usage(true),
          'events' => array_map(static fn ($e) => ['name' => $e::name()], $this->events->published()),
          'error' => $error,
        ]);
      }
    }
  }

  /**
   * Facade-first, legacy-fallback. A facade scope (a migrated drain or wake)
   * wins; otherwise the enclosing context is DERIVED from the legacy statics
   * so 0.2.x drains keep parenting commands into their own stories.
   */
  private function enclosing_context(): TraceContext {
    if (null !== $ctx = Correlation::peek()) {
      return $ctx;
    }

    $cause = null;
    if (null !== $causation_id = CorrelationContext::causation_id()) {
      $cause = new Cause($causation_id, match (CorrelationContext::causation_type()) {
        'long_process' => Kind::Trajectory,
        default => Kind::Fact,
      });
    }

    // peek, never get(): deriving the enclosing context must not MINT into
    // the legacy ambient (that corrupted the was-set check and made every
    // top-level command look like it ran inside a drain).
    $correlation = CorrelationContext::peek() ?? \TangibleDDD\Domain\Shared\Uuid::v4();

    return new TraceContext($correlation, $cause, CorrelationContext::sequence());
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
