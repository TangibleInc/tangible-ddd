<?php

namespace TangibleDDD\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentationCurrentnessTest extends TestCase {

  private const OPERATIONAL = [
    'README.md',
    'docs/README.md',
    'docs/wiring-a-consumer.md',
    'docs/consumer-design-interview.md',
    'docs/consumer-modules.md',
    'docs/migration-0.2-to-0.3.md',
    '.claude/skills/tangible-ddd/SKILL.md',
  ];

  private const HISTORICAL = [
    'docs/0.3-trace-context.md',
    'docs/dashboard/BUILD-OUTLINE.md',
    'docs/ddd-drill-inspector-dashboard-plan.md',
    'docs/framework-issues-from-consumer-review.md',
    'docs/integration-event-evolution.md',
  ];

  private const TRANSACTION_GUIDES = [
    'README.md',
    'docs/wiring-a-consumer.md',
    'docs/consumer-design-interview.md',
    'docs/consumer-modules.md',
    '.claude/skills/tangible-ddd/SKILL.md',
  ];

  private const REMOVED_PATTERNS = [
    'CorrelationContext::',
    'extends AsyncWordPressActionHandler',
    'extends AsyncWordpressActionHandler',
    'new CommandAuditMiddleware',
    "'@TangibleDDD\\Application\\Logging\\CommandAuditMiddleware'",
    'TransportEnvelope::',
    'composer require tangible/ddd:^0.2',
  ];

  private static string $root;

  public static function setUpBeforeClass(): void {
    self::$root = dirname( __DIR__, 3 );
  }

  /** @return iterable<string, array{string}> */
  public static function operational_files(): iterable {
    foreach ( self::OPERATIONAL as $file ) {
      yield $file => [ $file ];
    }
  }

  /** @return iterable<string, array{string}> */
  public static function historical_files(): iterable {
    foreach ( self::HISTORICAL as $file ) {
      yield $file => [ $file ];
    }
  }

  /** @return iterable<string, array{string}> */
  public static function transaction_guides(): iterable {
    foreach ( self::TRANSACTION_GUIDES as $file ) {
      yield $file => [ $file ];
    }
  }

  #[DataProvider( 'operational_files' )]
  public function test_operational_document_exists( string $file ): void {
    $this->assertFileExists( self::$root . '/' . $file );
  }

  #[DataProvider( 'operational_files' )]
  public function test_operational_document_does_not_prescribe_removed_apis( string $file ): void {
    $path = self::$root . '/' . $file;
    if ( ! file_exists( $path ) ) {
      $this->markTestIncomplete( "$file does not exist yet" );
    }

    $contents = (string) file_get_contents( $path );
    foreach ( self::REMOVED_PATTERNS as $pattern ) {
      $this->assertStringNotContainsString(
        $pattern,
        $contents,
        "$file presents removed API '$pattern' as current guidance"
      );
    }
  }

  #[DataProvider( 'transaction_guides' )]
  public function test_transaction_guidance_names_the_opt_in_marker( string $file ): void {
    $contents = (string) file_get_contents( self::$root . '/' . $file );

    $this->assertStringContainsString(
      'ITransactionalCommand',
      $contents,
      "$file must not imply that every command automatically opens a transaction"
    );
  }

  #[DataProvider( 'historical_files' )]
  public function test_historical_document_has_a_status_banner( string $file ): void {
    $path = self::$root . '/' . $file;
    $this->assertFileExists( $path );

    $contents = (string) file_get_contents( $path );
    $this->assertMatchesRegularExpression(
      '/^>\s*(?:\*\*)?Status:/mi',
      $contents,
      "$file must begin with an explicit Status banner"
    );
  }

  #[DataProvider( 'operational_files' )]
  public function test_local_links_in_operational_documents_resolve( string $file ): void {
    $path = self::$root . '/' . $file;
    if ( ! file_exists( $path ) ) {
      $this->markTestIncomplete( "$file does not exist yet" );
    }

    $contents = (string) file_get_contents( $path );
    $contents = preg_replace( '/^(?:```|~~~).*?^(?:```|~~~)\s*$/ms', '', $contents ) ?? $contents;
    preg_match_all( '/!?\[[^\]]*\]\(([^)]+)\)/', $contents, $matches );

    $broken = [];
    foreach ( $matches[1] as $raw_target ) {
      $target = $this->markdown_target( $raw_target );
      if ( $target === null ) {
        continue;
      }

      $resolved = dirname( $path ) . '/' . rawurldecode( $target );
      if ( ! file_exists( $resolved ) ) {
        $broken[] = $target;
      }
    }

    $this->assertSame(
      [],
      array_values( array_unique( $broken ) ),
      "$file contains broken local links:\n" . implode( "\n", $broken )
    );
  }

  private function markdown_target( string $raw_target ): ?string {
    $raw_target = trim( $raw_target );
    if ( str_starts_with( $raw_target, '<' ) ) {
      $closing = strpos( $raw_target, '>' );
      $target = $closing === false ? $raw_target : substr( $raw_target, 1, $closing - 1 );
    } else {
      $target = preg_split( '/\s+/', $raw_target, 2 )[0];
    }

    if (
      $target === ''
      || str_starts_with( $target, '#' )
      || str_starts_with( $target, '//' )
      || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $target ) === 1
    ) {
      return null;
    }

    return explode( '#', $target, 2 )[0];
  }
}
