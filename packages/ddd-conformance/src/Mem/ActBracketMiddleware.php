<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use League\Tactician\Middleware;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Support\Log;

/**
 * WAVE-1 STAND-IN for the core form of CorrelationMiddleware (register 1.4
 * split, "same ctor + optional ?IAuditSink, ?IActorProvider, ?IAuditPolicy,
 * ?IEnvironmentProvider"), which lands with the wave-2 move. The 0.6 class
 * reaches `$wpdb` through command_audit_enabled() even with audit off, so
 * the mem host cannot use it yet (api change request CONF-1).
 *
 * Mirrors the 0.6 bracket exactly where the scenarios look: the nesting
 * guard runs BEFORE the audit policy and holds with audit off; the command
 * runs inside Correlation::within(for_act(command id)); the audit row opens
 * before the transaction and closes after it with status and error; a sink
 * failure on close is logged and never replaces the business outcome.
 *
 * Replace with the core CorrelationMiddleware when wave 2 ships it.
 */
final class ActBracketMiddleware implements Middleware {

  /** @param (\Closure(string):void)|null $log */
  public function __construct(
    private readonly EventsUnitOfWork $events,
    private readonly IAuditSink $sink,
    private readonly IAuditPolicy $policy,
    private readonly IActorProvider $actor,
    private readonly IEnvironmentProvider $environment,
    private readonly IClock $clock,
    private readonly ?\Closure $log = null,
  ) {}

  public function execute($command, callable $next) {
    $enclosing = Correlation::peek() ?? TraceContext::root();

    if ($enclosing->cause?->kind === Kind::Act) {
      throw new CommandDispatchedInsideCommand(get_class($command), $enclosing->cause->label ?? $enclosing->cause->id);
    }

    $commandId = bin2hex(random_bytes(16));
    $audit = $this->policy->audits($command);
    $start = hrtime(true);

    if ($audit) {
      $this->sink->open(new AuditOpen(
        $commandId,
        $enclosing->correlation_id,
        get_class($command),
        $this->actor->current(),
        $enclosing->cause?->id,
        $enclosing->cause?->causation_type(),
        $this->policy->captureParameters($command) ? get_object_vars($command) : [],
        $this->environment->describe(),
        $this->clock->now(),
      ));
    }

    $status = 'success';
    $error = null;
    try {
      return Correlation::within($enclosing->for_act($commandId, get_class($command)), static fn () => $next($command));
    } catch (\Throwable $e) {
      $status = 'error';
      $error = ['type' => get_class($e), 'message' => $e->getMessage(), 'code' => (int) $e->getCode()];
      throw $e;
    } finally {
      if ($audit) {
        try {
          $this->sink->close(new AuditClose(
            $commandId,
            $status,
            (int) round((hrtime(true) - $start) / 1e6),
            memory_get_peak_usage(true),
            array_map(static fn ($e) => ['name' => $e::name(), 'reactions' => Reactions::of($e)], $this->events->published()),
            $error,
          ));
        } catch (\Throwable $sinkError) {
          Log::write($this->log, '[ddd audit] audit close failed after the command finished: ' . $sinkError->getMessage());
        }
      }
    }
  }
}
