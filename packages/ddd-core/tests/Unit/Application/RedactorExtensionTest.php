<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Runtime\Audit\Actor;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\Runtime\Audit\NotAudited;
use TangibleDDD\Runtime\Audit\Sensitive;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Testing\FixedActorProvider;
use TangibleDDD\Testing\InMemoryAuditSink;

final class IngestTrace {
  public function __construct(
    public readonly string $app_name,
    #[NotAudited] public readonly string $pfx_body,
    #[Sensitive] public readonly string $runner_secret_ref,
  ) {}
}

/**
 * D8: the audit redaction extension point. Passwords, tokens, PEMs and
 * binary bodies never reach the audit sink; hosts add keys and a predicate;
 * #[Sensitive] masks a property, #[NotAudited] leaves it out.
 */
final class RedactorExtensionTest extends TestCase {

  private const PEM = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\n-----END PRIVATE KEY-----\n";

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
  }

  public function test_token_and_password_key_families_are_masked(): void {
    [$safe, $paths] = (new Redactor())->redact([
      'stripe_token' => 'tok_abcdefgh',
      'csrftoken' => 'abcdefghij',
      'new_password' => 'hunter22',
      'db_passwd' => 'xyzxyzxyz',
      'webhook_secret' => 'whsec_123456',
      'private_key' => 'k-123456789',
      'token_count' => 3,
    ]);

    foreach (['stripe_token', 'csrftoken', 'new_password', 'db_passwd', 'webhook_secret', 'private_key'] as $k) {
      self::assertStringContainsString('*', (string) $safe[$k], $k);
      self::assertContains($k, $paths, $k);
    }
    self::assertSame(3, $safe['token_count'], 'a key that merely starts with token is not a token');
  }

  public function test_a_pem_value_is_redacted_whatever_its_key(): void {
    [$safe, $paths] = (new Redactor())->redact(['deploy' => ['key_material' => self::PEM]]);

    self::assertSame('pem', $safe['deploy']['key_material']['__redacted']);
    self::assertSame('PRIVATE KEY', $safe['deploy']['key_material']['label']);
    self::assertStringNotContainsString('MIIE', json_encode($safe));
    self::assertSame(['deploy.key_material'], $paths);
  }

  public function test_a_binary_value_is_summarised_without_content_and_stays_json_encodable(): void {
    $body = "PFX0\x00\x01\xff\xfe" . random_bytes(64) . 'END0';
    [$safe] = (new Redactor())->redact(['body' => $body]);

    self::assertSame('binary', $safe['body']['__summary']);
    self::assertSame(strlen($body), $safe['body']['length']);
    self::assertSame(hash('sha256', $body), $safe['body']['sha256']);
    self::assertArrayNotHasKey('preview', $safe['body']);
    self::assertNotFalse(json_encode($safe));
  }

  public function test_hosts_add_keys_and_a_predicate(): void {
    $r = new Redactor(
      ['Customer_Ssn'],
      static fn (string $path, mixed $value): bool => $path === 'card.number' || (is_string($value) && str_starts_with($value, 'sk_live_')),
    );

    [$safe, $paths] = $r->redact([
      'customer_ssn' => '123-45-6789',
      'card' => ['number' => '4242424242424242', 'brand' => 'visa'],
      'note' => 'sk_live_0000000000',
    ]);

    self::assertStringNotContainsString('123-45', $safe['customer_ssn']);
    self::assertSame('************4242', $safe['card']['number']);
    self::assertSame('visa', $safe['card']['brand']);
    self::assertStringNotContainsString('sk_live', $safe['note']);
    self::assertEqualsCanonicalizing(['customer_ssn', 'card.number', 'note'], $paths);
  }

  public function test_property_attributes_on_a_command(): void {
    [$safe, $paths] = (new Redactor())->redact_object(new IngestTrace('shop', "\x00\x01binary", 'vault:runner/7'));

    self::assertSame(['app_name' => 'shop', 'runner_secret_ref' => '**********er/7'], $safe);
    self::assertSame(['runner_secret_ref'], $paths);
  }

  public function test_the_act_bracket_audits_through_the_attributes(): void {
    $sink = new InMemoryAuditSink();
    $bracket = new CorrelationMiddleware(
      new AcmeConfig(),
      new EventsUnitOfWork(),
      new Redactor(),
      $sink,
      new FixedActorProvider(new Actor(ActorKind::Machine, 'tracer')),
    );

    $bracket->execute(new IngestTrace('shop', random_bytes(4096), 'vault:x'), static fn () => null);

    self::assertArrayNotHasKey('pfx_body', $sink->opened[0]->parameters);
    self::assertSame('shop', $sink->opened[0]->parameters['app_name']);
    self::assertNotFalse(json_encode($sink->opened[0]->parameters));
  }

  public function test_a_sensitive_binary_value_masks_without_leaking_bytes(): void {
    [$safe] = (new Redactor())->redact(['password' => "\xff\xfe\xfd\xfc\xfb"]);

    self::assertNotFalse(json_encode($safe));
    self::assertSame('[secret]', $safe['password']);
  }
}
