<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Ids\NameBasedUuid;
use TangibleDDD\Runtime\Process\IgnitionKey;

final class NameBasedUuidTest extends TestCase {

  /** RFC 4122 appendix namespaces; vectors cross-checked against Python's uuid.uuid5. */
  private const NS_DNS = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
  private const NS_URL = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

  public function test_rfc_4122_dns_vector(): void {
    self::assertSame('2ed6657d-e927-568b-95e1-2665a8aea6a2', NameBasedUuid::v5(self::NS_DNS, 'www.example.com'));
  }

  public function test_rfc_4122_url_vector(): void {
    self::assertSame('fcde3c85-2270-590f-9e7c-ee003d65e0e2', NameBasedUuid::v5(self::NS_URL, 'http://www.example.com/'));
  }

  public function test_python_dns_python_org_vector(): void {
    self::assertSame('886313e1-3b8a-5372-9b90-0c9aee199e5d', NameBasedUuid::v5(self::NS_DNS, 'python.org'));
  }

  public function test_is_deterministic_and_well_formed(): void {
    $a = NameBasedUuid::v5(self::NS_DNS, 'x');
    self::assertSame($a, NameBasedUuid::v5(self::NS_DNS, 'x'));
    self::assertNotSame($a, NameBasedUuid::v5(self::NS_DNS, 'y'));
    self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
  }

  public function test_accepts_an_uppercase_namespace(): void {
    self::assertSame(
      NameBasedUuid::v5(self::NS_DNS, 'python.org'),
      NameBasedUuid::v5(strtoupper(self::NS_DNS), 'python.org')
    );
  }

  public function test_rejects_a_namespace_that_is_not_a_uuid(): void {
    $this->expectException(\InvalidArgumentException::class);
    NameBasedUuid::v5('42', 'name');
  }

  public function test_ignition_key_is_uuid5_of_event_id_and_process_class(): void {
    $event_id = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

    self::assertSame(
      NameBasedUuid::v5($event_id, 'Acme\\Process\\Onboard'),
      IgnitionKey::for($event_id, 'Acme\\Process\\Onboard')
    );
    self::assertNotSame(
      IgnitionKey::for($event_id, 'Acme\\Process\\Onboard'),
      IgnitionKey::for($event_id, 'Acme\\Process\\Offboard')
    );
  }
}
