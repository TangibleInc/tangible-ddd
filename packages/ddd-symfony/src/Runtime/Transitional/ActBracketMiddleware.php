<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Transitional;

use League\Tactician\Middleware;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IAuditPolicy;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\IClock;

/**
 * TRANSITIONAL stand-in for the core form of CorrelationMiddleware (CONF-1:
 * "same ctor + optional ?IAuditSink, ?IActorProvider, ?IAuditPolicy,
 * ?IEnvironmentProvider"), which wave-2 core ships with the move. The 0.6
 * class reaches WordPress (command_audit_enabled, get_current_user_id) on
 * every path, so it cannot run on Symfony yet. The bundle wires this under
 * the service id `tangible_ddd.middleware.act_bracket`; round 3 points that
 * id at the core class and deletes this file.
 *
 * Same bracket as 0.6 where it matters: the nesting guard runs BEFORE the
 * audit policy and holds with audit off; the command runs inside
 * `Correlation::within(for_act(command id))`; the audit row opens before
 * the transaction (actor from IActorProvider, D5) and closes after it; a
 * sink failure on close is logged and never replaces the business outcome.
 */
final class ActBracketMiddleware implements Middleware {

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly EventsUnitOfWork $events,
    private readonly IAuditSink $sink,
    private readonly IAuditPolicy $policy,
    private readonly IActorProvider $actor,
    private readonly IEnvironmentProvider $environment,
    private readonly IClock $clock,
    private readonly Redactor $redactor = new Redactor(),
    ?LoggerInterface $logger = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  public function execute($command, callable $next) {
    $enclosing = Correlation::peek() ?? TraceContext::root();

    if ($enclosing->cause?->kind === Kind::Act) {
      throw new CommandDispatchedInsideCommand(get_class($command), $enclosing->cause->label ?? $enclosing->cause->id);
    }

    $commandId = bin2hex(random_bytes(16));
    $audit = $this->policy->audits($command);
    $start = hrtime(true);

    if ($audit) {
      $parameters = [];
      if ($this->policy->captureParameters($command)) {
        [$parameters] = $this->redactor->redact(get_object_vars($command));
      }
      $this->sink->open(new AuditOpen(
        $commandId,
        $enclosing->correlation_id,
        get_class($command),
        $this->actor->current(),
        $enclosing->cause?->id,
        $enclosing->cause?->causation_type(),
        $parameters,
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
          $this->logger->error('[ddd audit] audit close failed after the command finished: ' . $sinkError->getMessage(), ['exception' => $sinkError]);
        }
      }
    }
  }
}
