<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * The audit row written at bracket OPEN, where the enclosing cause is still
 * visible (mirrors today's command_audit_preflight fields;
 * CorrelationMiddleware.php:52-66). Host-specific context (wp blog_id, WP
 * version) travels in $environment.
 */
final class AuditOpen {

  /**
   * @param array<string, mixed> $parameters  already redacted
   * @param array<string, mixed> $environment IEnvironmentProvider::describe()
   */
  public function __construct(
    public readonly string $commandId,
    public readonly string $correlationId,
    public readonly string $commandName,
    public readonly Actor $actor,
    public readonly ?string $causationId,
    public readonly ?string $causationType,
    public readonly array $parameters,
    public readonly array $environment,
    public readonly \DateTimeImmutable $startedAt,
  ) {}
}
