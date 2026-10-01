<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\AuditClose;
use TangibleDDD\Runtime\Audit\AuditOpen;
use TangibleDDD\Runtime\Audit\IAuditSink;

use function TangibleDDD\WordPress\command_audit_finalise;
use function TangibleDDD\WordPress\command_audit_preflight;

/**
 * The wp IAuditSink (register 1.4, 3.9): one consumer's
 * `{prefix}_command_audit` table, written by the 0.6 functions
 * command_audit_preflight() / command_audit_finalise(), so the rows are
 * byte-for-byte what 0.6 wrote:
 *
 * - source/source_id from the Actor: user → ('user', id), cli → 'cli',
 *   system → 'system', machine → ('machine', id);
 * - blog_id = the current blog (multisite) or 1;
 * - environment as given (WpEnvironmentProvider: php, wp, plugin).
 *
 * Per consumer: WpHostPortFactory builds one for a consumer whose audit
 * table exists (command_audit_enabled()) and answers NullAuditSink
 * otherwise, which is 0.6's "audit off".
 *
 * Error behaviour: wpdb insert/update failures are not checked, as in 0.6.
 */
final class WpdbAuditSink implements IAuditSink {

  public function __construct(private readonly IDDDConfig $config) {}

  public function open(AuditOpen $r): void {
    command_audit_preflight($this->config, [
      'command_id' => $r->command_id,
      'correlation_id' => $r->correlation_id,
      'command_name' => $r->command_name,
      'source' => $r->actor->kind->value,
      'source_id' => in_array($r->actor->kind, [ActorKind::User, ActorKind::Machine], true) ? (string) ($r->actor->id ?? '') : '',
      'causation_id' => $r->causation_id,
      'causation_type' => $r->causation_type,
      'blog_id' => is_multisite() ? get_current_blog_id() : 1,
      'parameters' => $r->parameters,
      'environment' => $r->environment,
    ]);
  }

  public function close(AuditClose $r): void {
    command_audit_finalise($this->config, [
      'command_id' => $r->command_id,
      'status' => $r->status,
      'duration_ms' => $r->duration_ms,
      'peak_memory_bytes' => $r->peak_memory_bytes,
      'events' => $r->events,
      'error' => $r->error,
    ]);
  }
}
