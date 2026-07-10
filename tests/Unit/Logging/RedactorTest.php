<?php

namespace TangibleDDD\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Logging\Redactor;

class RedactorTest extends TestCase {

  private Redactor $redactor;

  protected function setUp(): void {
    $this->redactor = new Redactor();
  }

  public function test_plain_values_pass_through(): void {
    [$safe, $redactions] = $this->redactor->redact([
      'name' => 'Alice',
      'count' => 42,
      'active' => true,
    ]);

    $this->assertSame('Alice', $safe['name']);
    $this->assertSame(42, $safe['count']);
    $this->assertTrue($safe['active']);
    $this->assertEmpty($redactions);
  }

  public function test_sensitive_keys_are_masked(): void {
    [$safe, $redactions] = $this->redactor->redact([
      'password' => 'hunter2',
      'secret' => 'abc123',
      'token' => 'tok_xyz',
      'api_key' => 'key_1234567890',
    ]);

    // Passwords masked: all but last 4 chars replaced with *
    $this->assertStringContainsString('*', $safe['password']);
    $this->assertStringNotContainsString('hunter2', $safe['password']);

    $this->assertStringContainsString('*', $safe['secret']);
    $this->assertStringContainsString('*', $safe['token']);
    $this->assertStringContainsString('*', $safe['api_key']);

    $this->assertCount(4, $redactions);
  }

  public function test_sensitive_keys_case_insensitive(): void {
    [$safe, $redactions] = $this->redactor->redact([
      'Password' => 'hunter2',
      'SECRET' => 'abc',
    ]);

    $this->assertStringContainsString('*', $safe['Password']);
    $this->assertStringContainsString('*', $safe['SECRET']);
    $this->assertCount(2, $redactions);
  }

  public function test_nested_sensitive_keys(): void {
    [$safe, $redactions] = $this->redactor->redact([
      'config' => [
        'client_secret' => 'very-secret',
        'endpoint' => 'https://api.example.com',
      ],
    ]);

    $this->assertStringContainsString('***', $safe['config']['client_secret']);
    $this->assertSame('https://api.example.com', $safe['config']['endpoint']);
    $this->assertCount(1, $redactions);
  }

  public function test_long_strings_summarized(): void {
    $long = str_repeat('x', 2000);
    [$safe, $redactions] = $this->redactor->redact(['data' => $long]);

    $this->assertIsArray($safe['data']);
    $this->assertSame('long_string', $safe['data']['__summary']);
    $this->assertSame(2000, $safe['data']['length']);
    $this->assertArrayHasKey('sha256', $safe['data']);
    $this->assertArrayHasKey('preview', $safe['data']);
    $this->assertEmpty($redactions);
  }

  public function test_max_depth_produces_summary(): void {
    // Build a deeply nested array (7 levels, max is 6)
    $nested = ['value' => 'deep'];
    for ($i = 0; $i < 7; $i++) {
      $nested = ['level' => $nested];
    }

    [$safe, $redactions] = $this->redactor->redact($nested);

    // Walk down to find the summary
    $current = $safe;
    $found_summary = false;
    for ($i = 0; $i < 10; $i++) {
      if (isset($current['__summary']) && $current['__summary'] === 'max_depth') {
        $found_summary = true;
        break;
      }
      $current = $current['level'] ?? [];
    }

    $this->assertTrue($found_summary, 'Should produce max_depth summary at depth limit');
  }

  public function test_headers_array_redaction(): void {
    [$safe, $redactions] = $this->redactor->redact([
      'headers' => [
        ['key' => 'Content-Type', 'value' => 'application/json'],
        ['key' => 'Authorization', 'value' => 'Bearer secret-token-value'],
      ],
    ]);

    $this->assertSame('Content-Type', $safe['headers'][0]['key']);
    $this->assertSame('application/json', $safe['headers'][0]['value']);

    $this->assertSame('Authorization', $safe['headers'][1]['key']);
    $this->assertStringContainsString('***', $safe['headers'][1]['value']);
    $this->assertNotEmpty($redactions);
  }

  public function test_null_values_pass_through(): void {
    [$safe, $redactions] = $this->redactor->redact(['value' => null]);
    $this->assertNull($safe['value']);
    $this->assertEmpty($redactions);
  }

  public function test_datetime_objects_formatted(): void {
    $dt = new \DateTimeImmutable('2025-01-15T12:30:00+00:00');
    [$safe, $redactions] = $this->redactor->redact(['created_at' => $dt]);

    $this->assertSame('2025-01-15T12:30:00+00:00', $safe['created_at']);
    $this->assertEmpty($redactions);
  }

  public function test_backed_enum_logs_its_value(): void {
    [$safe, $redactions] = $this->redactor->redact(['schedule' => RedactorTestSchedule::EveryFourHours]);

    $this->assertSame('every_4_h', $safe['schedule']);
    $this->assertEmpty($redactions);
  }

  public function test_pure_enum_logs_its_name(): void {
    [$safe, ] = $this->redactor->redact(['suit' => RedactorTestSuit::Hearts]);

    $this->assertSame('Hearts', $safe['suit']);
  }

  public function test_mask_preserves_last_four_chars(): void {
    [$safe, ] = $this->redactor->redact(['password' => 'abcdefghij']);
    // 10 chars => 6 stars + last 4
    $this->assertSame('******ghij', $safe['password']);
  }

  public function test_mask_short_value(): void {
    [$safe, ] = $this->redactor->redact(['token' => 'ab']);
    // 2 chars => all stars
    $this->assertSame('**', $safe['token']);
  }

  public function test_large_array_truncated(): void {
    $large = [];
    for ($i = 0; $i < 60; $i++) {
      $large["key_$i"] = "value_$i";
    }

    [$safe, $redactions] = $this->redactor->redact(['items' => $large]);

    // Should have __summary key indicating truncation
    $this->assertArrayHasKey('__summary', $safe['items']);
    $this->assertStringContainsString('truncated_list', $safe['items']['__summary']);
  }
}

enum RedactorTestSchedule: string {
  case EveryFourHours = 'every_4_h';
}

enum RedactorTestSuit {
  case Hearts;
  case Spades;
}
