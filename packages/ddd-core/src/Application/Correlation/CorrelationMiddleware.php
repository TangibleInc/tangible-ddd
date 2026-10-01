<?php

namespace TangibleDDD\Application\Correlation;

use League\Tactician\Middleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Infrastructure\AuditSinkFailed;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AttributeAuditPolicy;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\Audit\NullAuditSink;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Audit\SapiActorProvider;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\SystemClock;
use Throwable;

/**
 * THE ACT BRACKET (0.3, spec §6.2): guard + scope + the audit record, one
 * owner. The record is written at bracket-open, where the ENCLOSING cause
 * is still visible — two separate middlewares can't both see the parent and
 * own the scope (build ruling #1; OTel's answer: the span record is written
 * by whatever opens the scope). The audit policy skips only the write; the
 * guard holds on every install (the 0.2.4 lesson).
 *
 * A command's audit row is the projection of the context it was born into:
 * correlation = the enclosing story, causation = the enclosing cause in the
 * at-rest dialect. Inside the scope, Correlation::current()->cause is this
 * act — facts published take their raiser edge from it.
 *
 * Core form (register 1.4, CONF-1): no host call on any path, audit off
 * included. The audit row goes through the ports:
 *
 *   IAuditSink           where the row goes (default: the host's sink for
 *                        this consumer, HostDefaults::for(); else NullAuditSink
 *                        = audit off). On WordPress that is WpdbAuditSink when
 *                        the consumer's command_audit table exists.
 *   IActorProvider       who acts (default: host, else Cli/System by SAPI)
 *   IAuditPolicy         whether to write, with or without parameters
 *                        (default: host, else AttributeAuditPolicy:
 *                        everything except #[Audit(false)] commands, D12)
 *   IEnvironmentProvider host context; `plugin` = the consumer's version is
 *                        appended (0.6 wrote {php, wp, plugin})
 *
 * The four are optional trailing constructor parameters (R2); the 0.6.5
 * `(IDDDConfig, EventsUnitOfWork, Redactor)` call is unchanged and resolves
 * them from HostDefaults per command.
 *
 * Command ids: 32 hex characters; a DeterministicCommandId hint (a listener
 * inside a fact cause, register 3.8) is used when pending.
 *
 * Sink failures never change the business outcome: a throwing open() or
 * close() is logged and signalled (AuditSinkFailed) and the command's result
 * or exception passes through untouched; a row whose open() failed is not
 * closed.
 */
final class CorrelationMiddleware implements Middleware {

  private ?AttributeAuditPolicy $default_policy = null;

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly EventsUnitOfWork $events,
    private readonly Redactor $redactor,
    private readonly ?IAuditSink $sink = null,
    private readonly ?IActorProvider $actor = null,
    private readonly ?IAuditPolicy $policy = null,
    private readonly ?IEnvironmentProvider $environment = null,
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
    $command_id = DeterministicCommandId::take() ?? bin2hex(random_bytes(16));
    $command_name = get_class($command);
    $sink = $this->sink();
    $policy = $this->policy();
    $audit = !$sink instanceof NullAuditSink && $policy->audits($command);
    $start_ts = microtime(true);

    if ($audit) {
      $audit = $this->open_row($sink, $policy, $command, $command_id, $command_name, $enclosing);
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
        // Names only — touches live in the touches table (the bus writes
        // it at publication; JOIN via command_id when you want them
        // together — owner ruling 2026-07-19: no duplication).
        $this->close_row($sink, new AuditClose(
          $command_id,
          $status,
          (int) round((microtime(true) - $start_ts) * 1000),
          memory_get_peak_usage(true),
          array_map(static fn ($e) => [
            'name' => $e::name(),
            'reactions' => Reactions::of($e),
          ], $this->events->published()),
          $error,
        ), $enclosing->correlation_id);
      }
    }
  }

  /** @return bool whether the row opened (and so must be closed) */
  private function open_row(IAuditSink $sink, IAuditPolicy $policy, object $command, string $command_id, string $command_name, TraceContext $enclosing): bool {
    $parameters = [];
    if ($policy->captures_parameters($command)) {
      [$parameters] = $this->redactor->redact_object($command);
    }

    try {
      $sink->open(new AuditOpen(
        $command_id,
        $enclosing->correlation_id,
        $command_name,
        $this->actor()->current(),
        $enclosing->cause?->id,
        $enclosing->cause?->causation_type(),
        $parameters,
        $this->environment()->describe() + ['plugin' => $this->config->version()],
        $this->clock()->now(),
      ));
      return true;
    } catch (Throwable $e) {
      $this->sink_failed('open', $command_id, $enclosing->correlation_id, $e);
      return false;
    }
  }

  private function close_row(IAuditSink $sink, AuditClose $row, string $correlation_id): void {
    try {
      $sink->close($row);
    } catch (Throwable $e) {
      $this->sink_failed('close', $row->command_id, $correlation_id, $e);
    }
  }

  private function sink_failed(string $phase, string $command_id, string $correlation_id, Throwable $e): void {
    Log::write(null, sprintf(
      '[%s-audit] audit %s failed for command %s; the command outcome is unaffected: %s',
      $this->config->prefix(), $phase, $command_id, $e->getMessage()
    ), 'error');
    (new AuditSinkFailed($command_id, $correlation_id, $phase, $e->getMessage()))->dispatch($this->config);
  }

  /**
   * The ambient scope wins; a genuinely flat dispatch (REST, CLI, a hook)
   * is the root of a fresh story. root() mints WITHOUT touching the ambient
   * — deriving the enclosing context must never persist a mint into the
   * worker.
   */
  private function enclosing_context(): TraceContext {
    return Correlation::peek() ?? TraceContext::root();
  }

  // Port resolution per command: HostDefaults is boot-time state, and a
  // per-call lookup keeps a middleware built before ddd-wp init correct.

  private function sink(): IAuditSink {
    return $this->sink ?? HostDefaults::for(IAuditSink::class, $this->config) ?? new NullAuditSink();
  }

  private function policy(): IAuditPolicy {
    return $this->policy ?? HostDefaults::get(IAuditPolicy::class) ?? $this->default_policy ??= new AttributeAuditPolicy();
  }

  private function actor(): IActorProvider {
    return $this->actor ?? HostDefaults::get(IActorProvider::class) ?? new SapiActorProvider();
  }

  private function environment(): IEnvironmentProvider {
    return $this->environment ?? HostDefaults::get(IEnvironmentProvider::class) ?? new PhpEnvironmentProvider();
  }

  private function clock(): IClock {
    return HostDefaults::get(IClock::class) ?? new SystemClock();
  }
}
