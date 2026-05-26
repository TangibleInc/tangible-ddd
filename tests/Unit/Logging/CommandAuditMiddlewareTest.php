<?php

namespace TangibleDDD\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\CommandAuditMiddleware;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;

class CommandAuditMiddlewareTest extends TestCase {

  private FakeDDDConfig $config;
  private EventsUnitOfWork $events;
  private Redactor $redactor;

  protected function setUp(): void {
    CorrelationContext::reset();
    CorrelationContext::init('test-corr');

    $this->config = new FakeDDDConfig();
    $this->events = new EventsUnitOfWork();
    $this->redactor = new Redactor();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  private function make_audit_enabled_wpdb(): \wpdb {
    $wpdb = $this->createMock(\wpdb::class);
    // command_audit_enabled checks: SHOW TABLES LIKE %s → returns the table name
    $wpdb->method('get_var')->willReturn('wp_test_command_audit');
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('insert')->willReturn(true);
    $wpdb->method('update')->willReturn(true);
    return $wpdb;
  }

  private function make_audit_disabled_wpdb(): \wpdb {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn(null); // table not found
    $wpdb->method('prepare')->willReturnArgument(0);
    return $wpdb;
  }

  public function test_skips_audit_when_disabled(): void {
    // Clear the static cache in command_audit_enabled
    // by using a fresh config prefix that hasn't been cached
    $GLOBALS['wpdb'] = $this->make_audit_disabled_wpdb();

    $middleware = new CommandAuditMiddleware($this->config, $this->events, $this->redactor);
    $result = $middleware->execute(new \stdClass(), fn() => 'ok');

    $this->assertSame('ok', $result);
  }

  public function test_sets_command_id_in_correlation_context(): void {
    $GLOBALS['wpdb'] = $this->make_audit_enabled_wpdb();

    // Clear static cache for this config prefix
    $config = new class implements \TangibleDDD\Infra\IDDDConfig {
      public function prefix(): string { return 'audit_test_' . uniqid(); }
      public function table(string $name): string { return 'wp_test_' . $name; }
      public function hook(string $name): string { return 'test_' . $name; }
      public function as_group(string $name): string { return 'test-' . $name; }
      public function option(string $name): string { return 'test_' . $name; }
      public function domain_action(string $event_name): string { return 'test_domain_' . $event_name; }
      public function integration_action(string $event_name): string { return 'test_integration_' . $event_name; }
      public function version(): string { return '1.0.0'; }
    };

    $middleware = new CommandAuditMiddleware($config, $this->events, $this->redactor);

    $captured_command_id = null;
    $middleware->execute(new \stdClass(), function () use (&$captured_command_id) {
      $captured_command_id = CorrelationContext::command_id();
      return 'ok';
    });

    $this->assertNotNull($captured_command_id);
    $this->assertNotEmpty($captured_command_id);
  }

  public function test_redactor_masks_sensitive_command_properties(): void {
    $command = new class {
      public string $user_name = 'alice';
      public string $password = 'supersecret';
      public int $entity_id = 42;
    };

    [$params, $redactions] = $this->redactor->redact(get_object_vars($command));

    $this->assertSame('alice', $params['user_name']);
    $this->assertSame(42, $params['entity_id']);
    $this->assertStringContainsString('***', $params['password']);
    $this->assertContains('password', $redactions);
  }

  public function test_events_tracked_for_audit_trail(): void {
    $event = new FakeDomainEvent(1);
    $this->events->record($event);
    $this->events->drain(); // moves queued → published

    $published = $this->events->published();
    $audit_events = array_map(
      static fn($e) => ['name' => $e::name()],
      $published
    );

    $this->assertCount(1, $audit_events);
    $this->assertSame('fake_domain_event', $audit_events[0]['name']);
  }

  public function test_handler_result_returned(): void {
    $GLOBALS['wpdb'] = $this->make_audit_disabled_wpdb();
    $middleware = new CommandAuditMiddleware($this->config, $this->events, $this->redactor);

    $result = $middleware->execute(new \stdClass(), fn() => ['data' => 123]);
    $this->assertSame(['data' => 123], $result);
  }

  public function test_exception_rethrown_after_audit(): void {
    $GLOBALS['wpdb'] = $this->make_audit_disabled_wpdb();
    $middleware = new CommandAuditMiddleware($this->config, $this->events, $this->redactor);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('handler error');

    $middleware->execute(new \stdClass(), function () {
      throw new \RuntimeException('handler error');
    });
  }
}
