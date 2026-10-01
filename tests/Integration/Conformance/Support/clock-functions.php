<?php
/**
 * Namespaced time() / gmdate() for the namespaces whose wave-2 WordPress
 * code reads the wall clock on the outbox path (see ScenarioTime).
 *
 * PHP resolves an unqualified function call inside a namespace to the
 * namespaced function first and caches that per call site, so this file must
 * be loaded before any code in these namespaces runs: the conformance
 * bootstrap requires it before Composer's autoloader. It is never loaded by
 * the plain wp-integration suite.
 *
 *   TangibleDDD\Infra\Persistence   OutboxRepository (scheduled_at, leases, backoff, pauses, DLQ)
 *   TangibleDDD\WordPress\Adapter   WpdbOutboxStore::append (created_at), WpdbOutboxAdministration (replay/retry reset)
 */

declare(strict_types=1);

namespace TangibleDDD\Infra\Persistence {

  use TangibleDDD\Tests\Integration\Conformance\Support\ScenarioTime;

  function time(): int {
    return ScenarioTime::now();
  }

  function gmdate(string $format, ?int $timestamp = null): string {
    return \gmdate($format, $timestamp ?? ScenarioTime::now());
  }
}

namespace TangibleDDD\WordPress\Adapter {

  use TangibleDDD\Tests\Integration\Conformance\Support\ScenarioTime;

  function time(): int {
    return ScenarioTime::now();
  }

  function gmdate(string $format, ?int $timestamp = null): string {
    return \gmdate($format, $timestamp ?? ScenarioTime::now());
  }
}
